<?php

namespace App\Services;

use App\Models\NplCase;
use App\Models\ActionSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class CaseScheduler
{
    public const SP1_MIN_DPD = 30;

    public const SP_GRACE_SP1_TO_SP2   = 7;
    public const SP_GRACE_SP2_TO_SP3   = 7;
    public const SP_GRACE_SP3_TO_SPT   = 7;
    public const SP_GRACE_SPT_TO_SPJAD = 7;

    // ✅ RULE BARU (DPD early warning)
    public const CONTACT_MIN_DPD = 16; // DPD > 15

    /** default source_system untuk schedule hasil scheduler */
    public const SOURCE_SYSTEM = 'scheduler';

    /** daftar type SP yang kita anggap “tahap peringatan” */
    protected array $spTypes = ['sp1', 'sp2', 'sp3', 'spt', 'spjad'];

    /**
     * Generate agenda awal (sekali) untuk case baru.
     * $withChain = false dipakai agar tidak recursive loop ketika dipanggil dari refreshWarningChain().
     */
    public function generateInitial(NplCase $case, bool $withChain = true): void
    {
        $case->loadMissing('loanAccount');
        $loan = $case->loanAccount;
        if (!$loan) return;

        // ✅ kalau sudah masuk tahap SP (ada SP legacy/existing), jangan bikin agenda awal lagi
        if ($this->hasAnySpAction($case) || $this->hasPendingSpSchedule($case)) {
            if ($withChain) $this->scheduleWarningChain($case);
            return;
        }

        $kolek = (int) $loan->kolek;

        if ($kolek === 2) {
            $intervalDays = 7;
        } elseif ($kolek === 3) {
            $intervalDays = 3;
        } else {
            $intervalDays = 2;
        }

        $firstFollowUp = now()->addDays($intervalDays);

        // Follow Up Awal (sekali)
        $this->createScheduleOnce($case, [
            'type'         => 'follow_up',
            'title'        => 'Follow Up Awal',
            'notes'        => 'Follow up kredit bermasalah via telepon/WA.',
            'scheduled_at' => $firstFollowUp,
            'source_system'=> self::SOURCE_SYSTEM,
        ]);

        // Visit Awal (hanya kalau kolek >= 4)
        if ($kolek >= 4) {
            $visitDate = now()->addDay();

            $this->createScheduleOnce($case, [
                'type'         => 'visit',
                'title'        => 'Kunjungan Lapangan Awal',
                'notes'        => 'Visit debitur untuk pemetaan kondisi usaha/pendapatan.',
                'scheduled_at' => $visitDate,
                'source_system'=> self::SOURCE_SYSTEM,
            ]);
        }

        if ($withChain) {
            $this->scheduleWarningChain($case);
        }
    }

    public function generateNextAfterAction(NplCase $case): void
    {
        $case->loadMissing('loanAccount');

        $loan = $case->loanAccount;
        if (!$loan) return;

        if ($case->closed_at) return;

        // ✅ kalau sudah masuk tahap SP / ada SP pending, jangan bikin follow-up lanjutan otomatis
        if ($this->hasAnySpAction($case) || $this->hasPendingSpSchedule($case)) {
            $this->scheduleWarningChain($case);
            return;
        }

        $kolek = (int) $loan->kolek;

        if ($kolek === 2) {
            $intervalDays = 7;
        } elseif ($kolek === 3) {
            $intervalDays = 3;
        } else {
            $intervalDays = 2;
        }

        $nextDate = now()->addDays($intervalDays);

        $this->createScheduleOnce($case, [
            'type'         => 'follow_up',
            'title'        => 'Follow Up Lanjutan',
            'notes'        => 'Tindak lanjut hasil komunikasi sebelumnya.',
            'scheduled_at' => $nextDate,
            'source_system'=> self::SOURCE_SYSTEM,
        ]);

        $this->scheduleWarningChain($case);
    }

    /**
     * Ini dipanggil dari command batch.
     * Di sini kita pastikan:
     * - initial agenda dibuat SEKALI (tapi skip kalau sudah masuk tahap SP)
     * - lalu refresh chain SP + agenda harian AO
     */
    public function refreshWarningChain(NplCase $case): void
    {
        // 1) pastikan initial agenda ada (tanpa panggil chain biar tidak loop)
        $this->ensureInitialAgendas($case);

        // 2) refresh chain + daily
        $this->scheduleWarningChain($case);
    }

    /**
     * ✅ Pastikan "Follow Up Awal" + "Kunjungan Awal" dibuat SEKALI.
     * Namun: jika sudah ada SP (legacy) / SP pending, jangan bikin initial agenda lagi.
     */
    protected function ensureInitialAgendas(NplCase $case): void
    {
        // ✅ kalau sudah masuk tahap SP, jangan bikin agenda awal
        if ($this->hasAnySpAction($case) || $this->hasPendingSpSchedule($case)) {
            return;
        }

        $hasInitial = $case->schedules()
            ->whereIn('title', ['Follow Up Awal', 'Kunjungan Lapangan Awal'])
            ->exists();

        if ($hasInitial) return;

        $hasAnyCore = $case->schedules()
            ->whereIn('type', ['follow_up', 'visit'])
            ->exists();

        if (!$hasAnyCore) {
            $this->generateInitial($case, false);
            return;
        }

        $hasFollowUp = $case->schedules()->where('type', 'follow_up')->exists();
        if (!$hasFollowUp) {
            $this->generateInitial($case, false);
        }
    }

    /**
     * 🔗 Rantai SP1 -> SP2 -> SP3 -> SPT -> SPJAD
     * PLUS agenda harian AO.
     *
     * ✅ PATCH BARU:
     * - DPD > 15 => pastikan ada WA + CALL (kalau belum masuk tahap SP)
     * - DPD >= 30 + CONTACT pending => eskalasi ke SP1 + catatan "belum WA/Telp"
     * - Jika action SP belum ada, eskalasi bisa jalan berdasarkan "pending schedule + grace"
     */
    protected function scheduleWarningChain(NplCase $case): void
    {
        $case->loadMissing('loanAccount');

        $loan = $case->loanAccount;
        if (!$loan) return;

        $dpd   = (int) $loan->dpd;
        $kolek = (int) $loan->kolek;

        // ✅ PATCH: definisi "masih problem" sekarang cukup case open dan DPD > 15
        // supaya kolek 1 dpd>15 juga masuk engine CONTACT.
        $stillProblem = $case->status === 'open' && ($dpd >= self::CONTACT_MIN_DPD);

        if (!$stillProblem) {
            return;
        }

        $now = now();

        // =============================
        // 0) Tentukan status “tahap SP”
        // =============================
        $lastMap = $this->getLastSpActionsMap($case);
        $lastSp1   = $lastMap['sp1'] ?? null;
        $lastSp2   = $lastMap['sp2'] ?? null;
        $lastSp3   = $lastMap['sp3'] ?? null;
        $lastSpt   = $lastMap['spt'] ?? null;
        $lastSpjad = $lastMap['spjad'] ?? null;

        $hasAnySpAction = (bool) ($lastSp1 || $lastSp2 || $lastSp3 || $lastSpt || $lastSpjad);
        $hasPendingSp   = $this->hasPendingSpSchedule($case);

        // =============================
        // ✅ PATCH 0.5) Ensure CONTACT (DPD>15) kalau belum masuk tahap SP
        // =============================
        if (!$hasAnySpAction && !$hasPendingSp && $dpd >= self::CONTACT_MIN_DPD) {
            $this->ensureContactWaCall($case, $now, $dpd);
        }

        // ✅ KEBIJAKAN LAMA:
        // Kalau sudah ada SP (legacy/existing) ATAU sedang ada SP pending,
        // maka JANGAN bikin agenda follow-up harian (fokus SP dulu).
        if (!$hasAnySpAction && !$hasPendingSp) {
            $this->ensureDailyFollowUp($case, $now);
        }

        // =============================
        // 1) Dedupe & reconcile SP
        // =============================
        foreach ($this->spTypes as $t) {
            $this->dedupePendingSchedules($case, $t);
        }

        $this->markPendingDoneIfActionExists($case, 'sp1', $lastSp1);
        $this->markPendingDoneIfActionExists($case, 'sp2', $lastSp2);
        $this->markPendingDoneIfActionExists($case, 'sp3', $lastSp3);
        $this->markPendingDoneIfActionExists($case, 'spt', $lastSpt);
        $this->markPendingDoneIfActionExists($case, 'spjad', $lastSpjad);

        $pendingTypes = $case->schedules()
            ->where('status', 'pending')
            ->whereIn('type', array_merge($this->spTypes, ['wa','call','follow_up','visit']))
            ->pluck('type')
            ->map(fn($t) => strtolower(trim((string)$t)))
            ->unique()
            ->values()
            ->all();

        $has = fn(string $t) => in_array($t, $pendingTypes, true);

        // =============================
        // 2) PATCH: Eskalasi berbasis PENDING schedule (kalau action SP belum ada)
        // =============================
        // Ini untuk memenuhi: "kalau dpd > 30 dan masih pending, jadwal ganti ke SP,
        // dengan catatan belum WA/Telp, begitu seterusnya."
        if (!$hasAnySpAction) {
            // target SP1 kalau DPD >= 30
            if ($dpd >= self::SP1_MIN_DPD) {
                $this->escalateIfNeededByPending($case, 'sp1', $now, $dpd, [
                    'from_types' => ['wa','call','follow_up','visit'],
                    'note' => 'DPD sudah >= 30. Step CONTACT belum ditindaklanjuti.',
                ]);
            }

            // eskalasi ke SP2 kalau SP1 masih pending lewat grace
            $this->escalateSpPendingToNext($case, 'sp1', 'sp2', self::SP_GRACE_SP1_TO_SP2, $dpd, $now);
            $this->escalateSpPendingToNext($case, 'sp2', 'sp3', self::SP_GRACE_SP2_TO_SP3, $dpd, $now);
            $this->escalateSpPendingToNext($case, 'sp3', 'spt', self::SP_GRACE_SP3_TO_SPT, $dpd, $now);
            $this->escalateSpPendingToNext($case, 'spt', 'spjad', self::SP_GRACE_SPT_TO_SPJAD, $dpd, $now);

            // Setelah eskalasi pending-based, kita lanjut ke logic lama juga (dedupe dll)
        }

        // =============================
        // 3) Chain SP logic (LEGACY / ACTION-BASED) - TETAP DIPERTAHANKAN
        // =============================

        if (!$lastSp1 && $dpd >= self::SP1_MIN_DPD) {
            // ✅ PATCH: kalau masuk SP stage, eskalasi follow-up pending (bukan sekedar cancel) biar ada catatan.
            $this->escalatePendingFollowUp($case, 'sp1', "Masuk tahap SP1 karena DPD={$dpd}. Jadwal CONTACT belum selesai.");

            $this->cancelPendingSpSchedulesExcept($case, ['sp1']);

            if (!$has('sp1')) {
                $this->createScheduleOnce($case, [
                    'type'         => 'sp1',
                    'title'        => 'Kirim SP1',
                    'scheduled_at' => $now,
                    'notes'        => 'Surat peringatan pertama',
                    'source_system'=> self::SOURCE_SYSTEM,
                ]);
            }
            return;
        }

        if ($lastSp1 && !$lastSp2) {
            $eligibleAt = Carbon::parse($lastSp1->action_at)->copy()->addDays(self::SP_GRACE_SP1_TO_SP2);
            if ($now->greaterThanOrEqualTo($eligibleAt)) {
                $this->escalatePendingFollowUp($case, 'sp2', "Eskalasi ke SP2. Jadwal follow-up masih ada yang pending.");
                $this->cancelPendingSpSchedulesExcept($case, ['sp2']);

                if (!$has('sp2')) {
                    $this->createScheduleOnce($case, [
                        'type'         => 'sp2',
                        'title'        => 'Kirim SP2',
                        'scheduled_at' => $eligibleAt,
                        'notes'        => 'Surat peringatan kedua',
                        'source_system'=> self::SOURCE_SYSTEM,
                    ]);
                }
            } else {
                $this->cancelPendingSpSchedulesExcept($case, $has('sp1') ? ['sp1'] : []);
            }
            return;
        }

        if ($lastSp2 && !$lastSp3) {
            $eligibleAt = Carbon::parse($lastSp2->action_at)->copy()->addDays(self::SP_GRACE_SP2_TO_SP3);
            if ($now->greaterThanOrEqualTo($eligibleAt)) {
                $this->escalatePendingFollowUp($case, 'sp3', "Eskalasi ke SP3. Jadwal follow-up masih ada yang pending.");
                $this->cancelPendingSpSchedulesExcept($case, ['sp3']);

                if (!$has('sp3')) {
                    $this->createScheduleOnce($case, [
                        'type'         => 'sp3',
                        'title'        => 'Kirim SP3',
                        'scheduled_at' => $eligibleAt,
                        'notes'        => 'Surat peringatan ketiga',
                        'source_system'=> self::SOURCE_SYSTEM,
                    ]);
                }
            } else {
                $this->cancelPendingSpSchedulesExcept($case, $has('sp2') ? ['sp2'] : []);
            }
            return;
        }

        if ($lastSp3 && !$lastSpt) {
            $eligibleAt = Carbon::parse($lastSp3->action_at)->copy()->addDays(self::SP_GRACE_SP3_TO_SPT);
            if ($now->greaterThanOrEqualTo($eligibleAt)) {
                $this->escalatePendingFollowUp($case, 'spt', "Eskalasi ke SPT. Jadwal follow-up masih ada yang pending.");
                $this->cancelPendingSpSchedulesExcept($case, ['spt']);

                if (!$has('spt')) {
                    $this->createScheduleOnce($case, [
                        'type'         => 'spt',
                        'title'        => 'Kirim SPT (Surat Peringatan Terakhir)',
                        'scheduled_at' => $eligibleAt,
                        'notes'        => 'Surat peringatan terakhir sebelum SPJAD',
                        'source_system'=> self::SOURCE_SYSTEM,
                    ]);
                }
            } else {
                $this->cancelPendingSpSchedulesExcept($case, $has('sp3') ? ['sp3'] : []);
            }
            return;
        }

        if ($lastSpt && !$lastSpjad) {
            $eligibleAt = Carbon::parse($lastSpt->action_at)->copy()->addDays(self::SP_GRACE_SPT_TO_SPJAD);
            if ($now->greaterThanOrEqualTo($eligibleAt)) {
                $this->escalatePendingFollowUp($case, 'spjad', "Eskalasi ke SPJAD. Jadwal follow-up masih ada yang pending.");
                $this->cancelPendingSpSchedulesExcept($case, ['spjad']);

                if (!$has('spjad')) {
                    $this->createScheduleOnce($case, [
                        'type'         => 'spjad',
                        'title'        => 'Kirim SPJAD (Pemberitahuan Jaminan Akan Dilelang)',
                        'scheduled_at' => $eligibleAt,
                        'notes'        => 'Surat pemberitahuan rencana lelang jaminan',
                        'source_system'=> self::SOURCE_SYSTEM,
                    ]);
                }
            } else {
                $this->cancelPendingSpSchedulesExcept($case, $has('spt') ? ['spt'] : []);
            }
            return;
        }

        $this->cancelPendingSpSchedulesExcept($case, []);
    }

    /**
     * ✅ NEW: bikin WA + CALL sekali (idempotent) saat DPD>15 dan belum tahap SP.
     */
    protected function ensureContactWaCall(NplCase $case, Carbon $now, int $dpd): void
    {
        // Jangan bikin dobel kalau sudah ada pending/done wa/call belakangan ini
        // (cukup 1 pending untuk tiap type)
        $scheduledAt = $this->pickWorkTime($now);

        $this->createScheduleOnce($case, [
            'type'         => 'wa',
            'title'        => 'WA Reminder (DPD > 15)',
            'notes'        => "Early warning otomatis. DPD={$dpd}.",
            'scheduled_at' => $scheduledAt,
            'source_system'=> self::SOURCE_SYSTEM,
        ]);

        $this->createScheduleOnce($case, [
            'type'         => 'call',
            'title'        => 'Telepon Debitur (DPD > 15)',
            'notes'        => "Early warning otomatis. DPD={$dpd}.",
            'scheduled_at' => $scheduledAt->copy()->addMinutes(15),
            'source_system'=> self::SOURCE_SYSTEM,
        ]);
    }

    /**
     * ✅ NEW: waktu yang rapih di jam kerja.
     */
    protected function pickWorkTime(Carbon $now): Carbon
    {
        $workStart = $now->copy()->startOfDay()->addHours(9);   // 09:00
        $workEnd   = $now->copy()->startOfDay()->addHours(17);  // 17:00

        if ($now->lessThan($workStart)) return $workStart;
        if ($now->between($workStart, $workEnd)) return $now->copy()->addMinutes(10);
        return $workStart->copy()->addDay();
    }

    /**
     * ✅ NEW: kalau kondisi terpenuhi, eskalasi pending CONTACT ke target SP (buat schedule target bila perlu).
     */
    protected function escalateIfNeededByPending(NplCase $case, string $targetSpType, Carbon $now, int $dpd, array $opt): void
    {
        $fromTypes = $opt['from_types'] ?? ['wa','call','follow_up','visit'];
        $noteBase  = (string)($opt['note'] ?? 'Eskalasi otomatis karena threshold.');

        // Kalau sudah ada pending/ done target SP, stop
        $hasTarget = $case->schedules()
            ->whereIn('status', ['pending','done'])
            ->where('type', $targetSpType)
            ->exists();

        if ($hasTarget) return;

        // Ada pending dariTypes?
        $pendingFrom = $case->schedules()
            ->where('status', 'pending')
            ->whereIn('type', $fromTypes)
            ->orderBy('scheduled_at')
            ->get();

        if ($pendingFrom->isEmpty()) {
            // tetap buat sp1 kalau dpd>=30, tapi tanpa catatan "pending"
            $this->createScheduleOnce($case, [
                'type'         => $targetSpType,
                'title'        => strtoupper($targetSpType) === 'SPJAD'
                    ? 'Kirim SPJAD (Pemberitahuan Jaminan Akan Dilelang)'
                    : 'Kirim '.strtoupper($targetSpType),
                'scheduled_at' => $now,
                'notes'        => "Auto schedule {$targetSpType}. DPD={$dpd}.",
                'source_system'=> self::SOURCE_SYSTEM,
            ]);
            return;
        }

        // 1) Buat target SP dulu
        $sp = $this->createScheduleOnce($case, [
            'type'         => $targetSpType,
            'title'        => strtoupper($targetSpType) === 'SPJAD'
                ? 'Kirim SPJAD (Pemberitahuan Jaminan Akan Dilelang)'
                : 'Kirim '.strtoupper($targetSpType),
            'scheduled_at' => $now,
            'notes'        => "Auto escalation to {$targetSpType}. DPD={$dpd}.",
            'source_system'=> self::SOURCE_SYSTEM,
        ]);

        // 2) Eskalasi pending CONTACT + catat
        $this->markSchedulesEscalated($pendingFrom, $sp->id, "{$noteBase} Naik ke {$targetSpType} (DPD={$dpd}).");
    }

    /**
     * ✅ NEW: eskalasi SP pending -> next type jika pending terlalu lama dan belum ada action.
     * Contoh: sp1 pending 7 hari => buat sp2, eskalasi sp1 jadi escalated.
     */
    protected function escalateSpPendingToNext(NplCase $case, string $fromSp, string $toSp, int $graceDays, int $dpd, Carbon $now): void
    {
        // Kalau sudah ada action "toSp" (legacy), nggak usah
        $lastMap = $this->getLastSpActionsMap($case);
        if (!empty($lastMap[$toSp])) return;

        // Kalau sudah ada pending toSp, nggak usah
        $hasTo = $case->schedules()
            ->where('status', 'pending')
            ->where('type', $toSp)
            ->exists();
        if ($hasTo) return;

        // Cari pending fromSp terlama
        $fromRow = $case->schedules()
            ->where('status', 'pending')
            ->where('type', $fromSp)
            ->orderBy('scheduled_at')
            ->first();

        if (!$fromRow) return;

        $baseAt = Carbon::parse($fromRow->scheduled_at);
        $eligibleAt = $baseAt->copy()->addDays($graceDays);

        if ($now->lessThan($eligibleAt)) return;

        // Buat toSp
        $sp = $this->createScheduleOnce($case, [
            'type'         => $toSp,
            'title'        => strtoupper($toSp) === 'SPJAD'
                ? 'Kirim SPJAD (Pemberitahuan Jaminan Akan Dilelang)'
                : (strtoupper($toSp) === 'SPT'
                    ? 'Kirim SPT (Surat Peringatan Terakhir)'
                    : 'Kirim '.strtoupper($toSp)),
            'scheduled_at' => $eligibleAt,
            'notes'        => "Auto escalation {$fromSp} -> {$toSp}. DPD={$dpd}.",
            'source_system'=> self::SOURCE_SYSTEM,
        ]);

        // Eskalasi fromSp (dan optional: contact pending juga)
        $pendingFrom = $case->schedules()
            ->where('status', 'pending')
            ->whereIn('type', [$fromSp])
            ->get();

        $this->markSchedulesEscalated(
            $pendingFrom,
            $sp->id,
            "AUTO-ESCALATE: {$fromSp} masih pending > {$graceDays} hari. Naik ke {$toSp}. DPD={$dpd}."
        );

        // Saat sudah masuk SP, follow-up/contact pending juga kita eskalasi (audit)
        $pendingContact = $case->schedules()
            ->where('status', 'pending')
            ->whereIn('type', ['wa','call','follow_up','visit'])
            ->get();

        if ($pendingContact->isNotEmpty()) {
            $this->markSchedulesEscalated(
                $pendingContact,
                $sp->id,
                "AUTO-ESCALATE: masuk {$toSp}, masih ada CONTACT pending. DPD={$dpd}."
            );
        }
    }

    /**
     * ✅ NEW: eskalasi follow-up/contact yang pending saat masuk tahap SP (audit trail).
     * Tidak menghapus, tidak hilang jejak.
     */
    protected function escalatePendingFollowUp(NplCase $case, string $toType, string $note): void
    {
        $pending = $case->schedules()
            ->where('status', 'pending')
            ->whereIn('type', ['follow_up', 'wa', 'call', 'visit'])
            ->get();

        if ($pending->isEmpty()) return;

        // Cari (atau buat) schedule tujuan (toType) agar ada target_id utk link
        $to = $case->schedules()
            ->where('status', 'pending')
            ->where('type', $toType)
            ->orderByDesc('id')
            ->first();

        $toId = $to?->id;

        $this->markSchedulesEscalated($pending, $toId, $note);
    }

    /**
     * ✅ NEW: marking batch schedules sebagai escalated (kalau kolom tersedia),
     * fallback ke cancelled kalau enum/kolom belum siap.
     */
    protected function markSchedulesEscalated($schedules, ?int $toId, string $note): void
    {
        $hasEscCols =
            Schema::hasColumn('action_schedules', 'escalated_at') &&
            Schema::hasColumn('action_schedules', 'escalation_note') &&
            Schema::hasColumn('action_schedules', 'escalated_to_id');

        foreach ($schedules as $sch) {
            /** @var ActionSchedule $sch */
            if (($sch->status ?? '') !== 'pending') continue;

            // Best effort: set status escalated kalau enum sudah ditambah
            $newStatus = 'escalated';

            try {
                $sch->status = $newStatus;
                if ($hasEscCols) {
                    $sch->escalated_at = now();
                    $sch->escalation_note = $note;
                    $sch->escalated_to_id = $toId;
                } else {
                    // fallback minimal: taruh note di notes
                    $sch->notes = trim((string)$sch->notes."\n".$note);
                    // kalau tidak ada enum escalated, paling aman cancelled
                    $sch->status = 'cancelled';
                    $sch->completed_at = now();
                }
                $sch->save();
            } catch (\Throwable $e) {
                // fallback keras: kalau enum belum diubah dan save error
                $sch->status = 'cancelled';
                $sch->completed_at = now();
                $sch->notes = trim((string)$sch->notes."\n".$note);
                $sch->save();
            }
        }
    }

    /**
     * ✅ Agenda harian "hari ini" jangan ter-block oleh follow_up future.
     * Tapi method ini sekarang hanya dipanggil kalau belum masuk tahap SP.
     */
    protected function ensureDailyFollowUp(NplCase $case, \Carbon\Carbon $now): void
    {
        $followTypes = ['follow_up', 'wa', 'call', 'visit'];

        $start = $now->copy()->startOfDay();
        $end   = $now->copy()->endOfDay();

        $hasPendingToday = $case->schedules()
            ->where('status', 'pending')
            ->whereIn('type', $followTypes)
            ->whereBetween('scheduled_at', [$start, $end])
            ->exists();

        if ($hasPendingToday) return;

        $recentNonSpAction = $case->actions()
            ->whereNotIn('action_type', $this->spTypes)
            ->where('action_at', '>=', $now->copy()->subHours(24))
            ->exists();

        if ($recentNonSpAction) return;

        $workStart = $now->copy()->startOfDay()->addHours(9);   // 09:00
        $workEnd   = $now->copy()->startOfDay()->addHours(17);  // 17:00

        if ($now->lessThan($workStart)) {
            $scheduledAt = $workStart;
        } elseif ($now->between($workStart, $workEnd)) {
            $scheduledAt = $now->copy()->addMinutes(30);
        } else {
            $scheduledAt = $workStart->copy()->addDay();
        }

        $this->createScheduleOnce($case, [
            'type'         => 'follow_up',
            'title'        => 'Follow-up Debitur (WA/Call/Visit)',
            'scheduled_at' => $scheduledAt,
            'notes'        => 'Agenda harian otomatis untuk AO (dibuat oleh scheduler).',
            'source_system'=> self::SOURCE_SYSTEM,
        ]);
    }

    protected function getLastSpActionsMap(NplCase $case): array
    {
        $types = $this->spTypes;

        $rows = $case->actions()
            ->whereIn('action_type', $types)
            ->orderByDesc('action_at')
            ->get();

        $map = [];
        foreach ($rows as $a) {
            $t = strtolower(trim((string) $a->action_type));
            if (!in_array($t, $types, true)) continue;
            if (!isset($map[$t])) $map[$t] = $a;
        }

        foreach ($types as $t) $map[$t] ??= null;

        return $map;
    }

    protected function hasAnySpAction(NplCase $case): bool
    {
        $map = $this->getLastSpActionsMap($case);
        foreach ($this->spTypes as $t) {
            if (!empty($map[$t])) return true;
        }
        return false;
    }

    protected function hasPendingSpSchedule(NplCase $case): bool
    {
        return $case->schedules()
            ->where('status', 'pending')
            ->whereIn('type', $this->spTypes)
            ->exists();
    }

    protected function cancelPendingFollowUp(NplCase $case): int
    {
        return (int) $case->schedules()
            ->where('status', 'pending')
            ->whereIn('type', ['follow_up', 'wa', 'call', 'visit'])
            ->update([
                'status'       => 'cancelled',
                'completed_at' => now(),
            ]);
    }

    protected function cancelPendingSpSchedulesExcept(NplCase $case, array $keepTypes): int
    {
        $keepTypes = array_values(array_unique(array_map(fn($t) => strtolower(trim((string)$t)), $keepTypes)));
        $spTypes   = $this->spTypes;

        $q = $case->schedules()
            ->where('status', 'pending')
            ->whereIn('type', $spTypes);

        if (!empty($keepTypes)) {
            $q->whereNotIn('type', $keepTypes);
        }

        return (int) $q->update([
            'status'       => 'cancelled',
            'completed_at' => now(),
        ]);
    }

    protected function resolveAssigneeId(NplCase $case): ?int
    {
        // 1) Prioritas: PIC (sudah ada di npl_cases)
        if (!empty($case->pic_user_id)) {
            return (int) $case->pic_user_id;
        }

        // 2) Fallback: AO user id dari loanAccount (kalau memang ada)
        $case->loadMissing('loanAccount');
        if (!empty($case->loanAccount?->ao_user_id)) {
            return (int) $case->loanAccount->ao_user_id;
        }

        return null;
    }

    protected function createScheduleOnce(NplCase $case, array $data): ActionSchedule
    {
        $type = (string)($data['type'] ?? '');
        $this->dedupePendingSchedules($case, $type);

        $assigneeId = $this->resolveAssigneeId($case);

        return $case->schedules()->updateOrCreate(
            [
                'npl_case_id' => $case->id,
                'type'        => $type,
                'status'      => 'pending',
            ],
            [
                'assigned_to'  => $assigneeId,
                'title'        => $data['title'] ?? null,
                'notes'        => $data['notes'] ?? null,
                'scheduled_at' => $data['scheduled_at'],
                'created_by'   => auth()->id() ?: null,
                'source_system'=> $data['source_system'] ?? self::SOURCE_SYSTEM,
            ]
        );
    }

    protected function markPendingDoneIfActionExists(NplCase $case, string $type, $lastAction): void
    {
        if (!$lastAction) return;

        $case->schedules()
            ->where('type', $type)
            ->where('status', 'pending')
            ->update([
                'status'       => 'done',
                'completed_at' => now(),
            ]);
    }

    /**
     * ✅ Dedupe yang keep schedule TERBARU (scheduled_at desc, id desc)
     * Yang lain jadi cancelled (audit trail tetap ada).
     */
    protected function dedupePendingSchedules(NplCase $case, string $type): void
    {
        $ids = $case->schedules()
            ->where('type', $type)
            ->where('status', 'pending')
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->pluck('id');

        if ($ids->count() <= 1) return;

        $keepId = $ids->first();

        $case->schedules()
            ->where('type', $type)
            ->where('status', 'pending')
            ->where('id', '!=', $keepId)
            ->update([
                'status'       => 'cancelled',
                'completed_at' => now(),
            ]);
    }

    public function rebuildSpSchedulesForCase(NplCase $case): void
    {
        $spTypes = $this->spTypes;

        // ⚠️ Tetap sesuai fungsi lama: reset pending SP saja
        $case->schedules()
            ->where('status', 'pending')
            ->whereIn('type', $spTypes)
            ->update([
                'status'       => 'cancelled',
                'completed_at' => now(),
            ]);

        $this->refreshWarningChain($case);
    }

    // di dalam class CaseScheduler
    protected string $sourceSystem = self::SOURCE_SYSTEM;

    public function setSourceSystem(string $source): self
    {
        $this->sourceSystem = trim($source) !== '' ? trim($source) : self::SOURCE_SYSTEM;
        return $this;
    }

    // ✅ Alias untuk kompatibilitas Job/Controller lama
    public function rebuildForCase(NplCase $case): void
    {
        // default: rebuild SP schedules (cancel pending SP, lalu refresh chain)
        $this->rebuildSpSchedulesForCase($case);
    }

}
