<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FeKpiMonthlyService
{
    // ====== Weights KPI FE (sesuai slide) ======
    public array $weights = [
        'os_turun' => 0.40,
        'migrasi'  => 0.40,
        'penalty'  => 0.20,
    ];

    // ====== Mode ======
    public function resolveMode(Carbon $period): string
    {
        return $period->greaterThanOrEqualTo(now()->startOfMonth()) ? 'realtime' : 'eom';
    }

    // ====== Scoring helper: KPI yang berbasis pencapaian % ======
    // 0-24=1, 25-49=2, 50-74=3, 75-99=4, 100=5, >100=6
    public function scoreFromAchievement(float $ach): float
    {
        if ($ach > 100) return 6.0;
        if ($ach >= 100) return 5.0;
        if ($ach >= 75)  return 4.0;
        if ($ach >= 50)  return 3.0;
        if ($ach >= 25)  return 2.0;
        return 1.0;
    }

    // ====== Scoring Migrasi NPL (reverse) sesuai slide ======
    // >1.5=1, 1-1.49=2, 0.5-0.99=3, 0.3-0.49=4, 0.01-0.3=5, 0=6
    public function scoreMigrasiFromPct(float $migrasiPct): float
    {
        $p = max(0.0, $migrasiPct);

        if ($p <= 0.0)    return 6.0;
        if ($p <= 0.30)   return 5.0;
        if ($p <= 0.49)   return 4.0;
        if ($p <= 0.99)   return 3.0;
        if ($p <= 1.49)   return 2.0;
        return 1.0;
    }

    public function pct(float $actual, float $target): float
    {
        if ($target <= 0) return 0.0;
        return round(($actual / $target) * 100.0, 2);
    }

    // reverse achievement: makin kecil actual makin bagus (dibatasi biar display masuk akal)
    public function achReverse(float $actual, float $target): float
    {
        if ($target <= 0) return 0.0;
        if ($actual <= 0) return 100.0;
        $v = round(($target / $actual) * 100.0, 2);
        return min($v, 200.0);
    }

    // =========================================================
    // MAIN: build FE KPI for period
    // return: ['items' => collection, 'tlFeRecap' => object|null]
    // =========================================================
    public function buildForPeriod(string $periodYm, $leaderUser = null): array
    {
        $period     = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        $periodDate = $period->toDateString();
        $mode       = $this->resolveMode($period);

        $prev     = $period->copy()->subMonth()->startOfMonth();
        $prevDate = $prev->toDateString();

        // ===== Targets map (FE) =====
        $targetMap = DB::table('kpi_fe_targets')
            ->whereDate('period', $periodDate)
            ->get()
            ->keyBy('fe_user_id');

        // ===== FE Users =====
        $feUsers = DB::table('users')
            ->select(['id', 'name', 'level', 'ao_code'])
            ->whereRaw("UPPER(TRIM(level)) = 'FE'")
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code) <> ''")
            ->orderBy('name')
            ->get();

        // ===== Kolom di loan_installments =====
        $paidDateCol = 'paid_date';
        $penaltyCol  = 'penalty_paid';

        // untuk upsert
        $now          = now();
        $calculatedBy = auth()->id();

        // ===== Build items =====
        $items = $feUsers->map(function ($u) use (
            $period, $periodDate, $prevDate, $mode,
            $targetMap, $paidDateCol, $penaltyCol,
            $now, $calculatedBy
        ) {
            $feId = (int) $u->id;
            $ao   = (string) $u->ao_code;

            // ========== TARGET (fallback) ==========
            $tg = $targetMap->get($feId);

            // ✅ Nett OS turun kembali RUPIAH
            $targetOsTurunRp = (float)($tg->target_os_turun_kol2 ?? 0);

            // migrasi tetap % (reverse)
            $targetMigrasi = (float)($tg->target_migrasi_npl_pct ?? 0.3000);

            // denda tetap rupiah
            $targetPenalty = (float)($tg->target_penalty_paid ?? 0);

            // =========================================================
            // 1) OS Kol2 awal (prev month snapshot)
            // =========================================================
            $osAwal = (float) DB::table('loan_account_snapshots_monthly')
                ->whereDate('snapshot_month', $prevDate)
                ->where('ao_code', $ao)
                ->where('kolek', 2)
                ->sum('outstanding');

            // =========================================================
            // 2) OS Kol2 akhir
            // =========================================================
            if ($mode === 'eom') {
                $osAkhir = (float) DB::table('loan_account_snapshots_monthly')
                    ->whereDate('snapshot_month', $periodDate)
                    ->where('ao_code', $ao)
                    ->where('kolek', 2)
                    ->sum('outstanding');
            } else {
                $osAkhir = (float) DB::table('loan_accounts')
                    ->where('ao_code', $ao)
                    ->where('kolek', 2)
                    ->sum('outstanding');
            }

            // =========================================================
            // 3) Migrasi NPL: dari kolek2 -> kolek>=3 (basis OS awal)
            // =========================================================
            if ($mode === 'eom') {
                $migrasiOs = (float) DB::table('loan_account_snapshots_monthly as p')
                    ->join('loan_account_snapshots_monthly as c', function ($j) use ($prevDate, $periodDate) {
                        $j->on('c.account_no', '=', 'p.account_no')
                          ->whereDate('c.snapshot_month', '=', $periodDate);
                    })
                    ->whereDate('p.snapshot_month', $prevDate)
                    ->where('p.ao_code', $ao)
                    ->where('p.kolek', 2)
                    ->where('c.kolek', '>=', 3)
                    ->sum('p.outstanding');
            } else {
                $migrasiOs = (float) DB::table('loan_account_snapshots_monthly as p')
                    ->join('loan_accounts as a', 'a.account_no', '=', 'p.account_no')
                    ->whereDate('p.snapshot_month', $prevDate)
                    ->where('p.ao_code', $ao)
                    ->where('p.kolek', 2)
                    ->where('a.kolek', '>=', 3)
                    ->sum('p.outstanding');
            }

            $migrasiPct = ($osAwal > 0)
                ? round(($migrasiOs / $osAwal) * 100.0, 4)
                : 0.0;

            // =========================================================
            // 4) Split: Turun total vs migrasi vs murni
            // =========================================================
            $osTurunTotal   = max(0.0, round($osAwal - $osAkhir, 2));
            $osTurunMigrasi = max(0.0, min($osTurunTotal, (float)$migrasiOs)); // clamp
            $osTurunMurni   = max(0.0, round($osTurunTotal - $osTurunMigrasi, 2));

            // =========================================================
            // ✅ 4B) Nett % hanya untuk INFO (bukan KPI scoring)
            // =========================================================
            $osTurunPctInfo = ($osAwal > 0)
                ? round(($osTurunMurni / $osAwal) * 100.0, 4)
                : 0.0;

            // =========================================================
            // 5) Denda masuk selama bulan period
            // =========================================================
            $start = $period->copy()->startOfMonth()->toDateString();
            $end   = $period->copy()->endOfMonth()->toDateString();

            $penaltyTotal = 0.0;
            try {
                $penaltyTotal = (float) DB::table('loan_installments')
                    ->where('ao_code', $ao)
                    ->whereBetween($paidDateCol, [$start, $end])
                    ->sum($penaltyCol);
            } catch (\Throwable $e) {
                $penaltyTotal = 0.0;
            }

            // =====================
            // ACHIEVEMENT
            // =====================
            // ✅ Nett OS turun kembali RUPIAH
            $achOsTurun = $this->pct($osTurunMurni, $targetOsTurunRp);

            $achPenalty = $this->pct($penaltyTotal, $targetPenalty);
            $achMigrasi = $this->achReverse($migrasiPct, $targetMigrasi);

            // =====================
            // SCORE
            // =====================
            $scoreOsTurun = $this->scoreFromAchievement($achOsTurun);
            $scorePenalty = $this->scoreFromAchievement($achPenalty);
            $scoreMigrasi = $this->scoreMigrasiFromPct($migrasiPct);

            // =====================
            // PI
            // =====================
            $piOsTurun = round($scoreOsTurun * ($this->weights['os_turun'] ?? 0), 2);
            $piMigrasi = round($scoreMigrasi * ($this->weights['migrasi'] ?? 0), 2);
            $piPenalty = round($scorePenalty * ($this->weights['penalty'] ?? 0), 2);

            $totalPi = round($piOsTurun + $piMigrasi + $piPenalty, 2);

            // =====================
            // Baseline flag sederhana
            // =====================
            $baselineOk   = true;
            $baselineNote = null;
            if ($mode === 'eom' && $osAwal <= 0 && $osAkhir <= 0) {
                $baselineOk   = false;
                $baselineNote = 'OS Kol2 awal/akhir nol (cek snapshot / mapping ao_code).';
            }

            // =====================
            // UPSERT monthly
            // =====================
            $row = [
                'period'     => $periodDate,
                'calc_mode'  => $mode,
                'fe_user_id' => $feId,
                'ao_code'    => $ao,

                'os_kol2_awal'  => $osAwal,
                'os_kol2_akhir' => $osAkhir,

                // legacy + split
                'os_kol2_turun'         => $osTurunTotal, // legacy
                'os_kol2_turun_total'   => $osTurunTotal,
                'os_kol2_turun_murni'   => $osTurunMurni,
                'os_kol2_turun_migrasi' => $osTurunMigrasi,

                // ✅ info percent
                'os_kol2_turun_pct' => $osTurunPctInfo,

                'migrasi_npl_os'  => $migrasiOs,
                'migrasi_npl_pct' => $migrasiPct,

                'penalty_paid_total' => $penaltyTotal,

                // targets
                'target_os_turun_kol2'   => $targetOsTurunRp,
                'target_migrasi_npl_pct' => $targetMigrasi,
                'target_penalty_paid'    => $targetPenalty,

                // achievement
                'ach_os_turun_pct' => $achOsTurun, // (nama kolom legacy, tapi isinya achievement nett OS turun)
                'ach_migrasi_pct'  => $achMigrasi,
                'ach_penalty_pct'  => $achPenalty,

                // score
                'score_os_turun' => $scoreOsTurun,
                'score_migrasi'  => $scoreMigrasi,
                'score_penalty'  => $scorePenalty,

                // pi
                'pi_os_turun' => $piOsTurun,
                'pi_migrasi'  => $piMigrasi,
                'pi_penalty'  => $piPenalty,
                'total_score_weighted' => $totalPi,

                'baseline_ok'   => $baselineOk ? 1 : 0,
                'baseline_note' => $baselineNote,

                'calculated_by' => $calculatedBy,
                'calculated_at' => $now,
                'updated_at'    => $now,
                'created_at'    => $now,
            ];

            DB::table('kpi_fe_monthlies')->upsert(
                [$row],
                ['period', 'calc_mode', 'fe_user_id'],
                [
                    'ao_code',
                    'os_kol2_awal', 'os_kol2_akhir',

                    'os_kol2_turun',
                    'os_kol2_turun_total',
                    'os_kol2_turun_murni',
                    'os_kol2_turun_migrasi',
                    'os_kol2_turun_pct',

                    'migrasi_npl_os', 'migrasi_npl_pct',
                    'penalty_paid_total',

                    'target_os_turun_kol2', 'target_migrasi_npl_pct', 'target_penalty_paid',
                    'ach_os_turun_pct', 'ach_migrasi_pct', 'ach_penalty_pct',
                    'score_os_turun', 'score_migrasi', 'score_penalty',
                    'pi_os_turun', 'pi_migrasi', 'pi_penalty',
                    'total_score_weighted',
                    'baseline_ok', 'baseline_note',
                    'calculated_by', 'calculated_at',
                    'updated_at',
                ]
            );

            return (object) [
                'user_id' => $feId,
                'name'    => (string) $u->name,
                'ao_code' => $ao,
                'level'   => (string) $u->level,
                'mode'    => $mode,

                // raw
                'os_kol2_awal'  => $osAwal,
                'os_kol2_akhir' => $osAkhir,

                'os_kol2_turun_total'   => $osTurunTotal,
                'os_kol2_turun_murni'   => $osTurunMurni,
                'os_kol2_turun_migrasi' => $osTurunMigrasi,

                // ✅ info
                'os_kol2_turun_pct' => $osTurunPctInfo,

                'migrasi_npl_os'  => $migrasiOs,
                'migrasi_npl_pct' => $migrasiPct,

                'penalty_paid_total' => $penaltyTotal,

                // targets
                'target_os_turun_kol2'   => $targetOsTurunRp,
                'target_migrasi_npl_pct' => $targetMigrasi,
                'target_penalty_paid'    => $targetPenalty,

                // achievement
                'ach_os_turun_pct' => $achOsTurun,
                'ach_migrasi_pct'  => $achMigrasi,
                'ach_penalty_pct'  => $achPenalty,

                // score
                'score_os_turun' => $scoreOsTurun,
                'score_migrasi'  => $scoreMigrasi,
                'score_penalty'  => $scorePenalty,

                // pi
                'pi_os_turun' => $piOsTurun,
                'pi_migrasi'  => $piMigrasi,
                'pi_penalty'  => $piPenalty,
                'pi_total'    => $totalPi,

                // =========================================================
                // ✅ ALIAS UNTUK sheet_fe.blade.php (tetap)
                // =========================================================
                'target_nett_os_down'     => $targetOsTurunRp,
                'nett_os_down'            => $osTurunMurni,
                'nett_os_down_total'      => $osTurunTotal,
                'nett_os_down_migrasi'    => $osTurunMigrasi,
                'nett_os_down_pct_info'   => $osTurunPctInfo,   // ✅ tambahan untuk display

                'ach_nett_os_down'        => $achOsTurun,
                'score_nett_os_down'      => $scoreOsTurun,
                'pi_nett_os_down'         => $piOsTurun,

                'target_npl_migration_pct'=> $targetMigrasi,
                'npl_migration_pct'       => $migrasiPct,
                'ach_npl_migration'       => $achMigrasi,
                'score_npl_migration'     => $scoreMigrasi,
                'pi_npl_migration'        => $piMigrasi,

                'target_penalty'          => $targetPenalty,
                'penalty_actual'          => $penaltyTotal,
                'ach_penalty'             => $achPenalty,
                'score_penalty'           => $scorePenalty,
                'pi_penalty'              => $piPenalty,
            ];
        });

        // ===== TLFE recap =====
        $asOfDate = $period->copy()->endOfMonth()->toDateString();
        $tlFeRecap = $this->buildTlFeRecap($items, $asOfDate, $leaderUser);

        return [
            'period'   => $period,
            'mode'     => $mode,
            'weights'  => $this->weights,
            'items'    => $items,
            'tlFeRecap'=> $tlFeRecap,
        ];
    }

    // =========================================================
    // TLFE RECAP
    // =========================================================
    private function leaderRoleValue($user): string
    {
        $role = '';
        if ($user) {
            if (method_exists($user, 'roleValue')) $role = (string)($user->roleValue() ?? '');
            if (trim($role) === '') $role = (string)($user->level ?? '');
        }
        $role = strtoupper(trim($role));
        return $role !== '' ? $role : 'LEADER';
    }

    private function resolveScopeUserIds($leader, Carbon $period): array
    {
        $leaderId   = (int)($leader?->id ?? 0);
        $leaderRole = strtoupper(trim((string)$this->leaderRoleValue($leader)));
        if ($leaderId <= 0) return [];

        $start = $period->copy()->startOfMonth()->toDateString();
        $end   = $period->copy()->endOfMonth()->toDateString();

        $roleAliases = match ($leaderRole) {
            'TLFE' => ['tlfe', 'tl', 'teamleader', 'leader'],
            'KSL'  => ['ksl', 'kasi', 'kasi lending'],
            'KBL'  => ['kbl', 'kabag', 'kabag lending'],
            default => [strtolower($leaderRole)],
        };

        return DB::table('org_assignments')
            ->where('leader_id', $leaderId)
            ->whereIn(DB::raw('LOWER(TRIM(leader_role))'), $roleAliases)
            ->where('is_active', 1)
            // ✅ overlap: effective_from <= end AND (effective_to null OR >= start)
            ->whereDate('effective_from', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $start);
            })
            ->pluck('user_id')
            ->map(fn($x) => (int)$x)
            ->values()
            ->all();
    }

    private function buildTlFeRecap($items, string $periodDate, $leaderUser = null): ?object
    {
        $leader = $leaderUser ?: auth()->user();
        if (!$leader) return null;

        $leaderRole = $this->leaderRoleValue($leader);

        $scopeUserIds = $this->resolveScopeUserIds($leader, Carbon::parse($periodDate));
        if (empty($scopeUserIds)) return null;

        $scoped = $items->filter(fn($it) => in_array((int)$it->user_id, $scopeUserIds, true));
        if ($scoped->count() <= 0) return null;

        // =====================
        // Ranking FE (Scope TLFE)
        // =====================
        $ranked = $scoped->sortByDesc(function ($it) {
            return (float)($it->pi_total ?? 0);
        })->values();

        $rankings = $ranked->map(function ($it, $i) {
            return (object)[
                'rank'      => $i + 1,
                'user_id'   => (int)($it->user_id ?? 0),
                'name'      => (string)($it->name ?? '-'),
                'ao_code'   => (string)($it->ao_code ?? '-'),
                'pi_total'  => (float)($it->pi_total ?? 0),

                // breakdown biar informatif
                'pi_os'     => (float)($it->pi_nett_os_down ?? 0),
                'pi_mg'     => (float)($it->pi_npl_migration ?? 0),
                'pi_pen'    => (float)($it->pi_penalty ?? 0),

                // optional: achievement ringkas
                'ach_os'    => (float)($it->ach_nett_os_down ?? 0),
                'ach_mg'    => (float)($it->ach_npl_migration ?? 0),
                'ach_pen'   => (float)($it->ach_penalty ?? 0),
                
            ];
        });

        // ✅ Nett OS turun recap pakai NOMINAL MURNI
        $sumTargetOsTurun = (float) $scoped->sum('target_os_turun_kol2');
        $sumOsTurunMurni  = (float) $scoped->sum('os_kol2_turun_murni');
        $sumOsTurunTotal  = (float) $scoped->sum('os_kol2_turun_total');
        $sumOsTurunMigr   = (float) $scoped->sum('os_kol2_turun_migrasi');

        // ✅ info % (scope) = sum(murni)/sum(os_awal)
        $sumOsAwal        = (float) $scoped->sum('os_kol2_awal');
        $sumPctInfo = ($sumOsAwal > 0)
            ? round(($sumOsTurunMurni / $sumOsAwal) * 100.0, 4)
            : 0.0;

        $sumPenaltyTarget = (float) $scoped->sum('target_penalty_paid');
        $sumPenalty       = (float) $scoped->sum('penalty_paid_total');

        $sumMigrasiOs     = (float) $scoped->sum('migrasi_npl_os');

        $migrasiPctTotal = ($sumOsAwal > 0)
            ? round(($sumMigrasiOs / $sumOsAwal) * 100.0, 4)
            : 0.0;

        // target migrasi TLFE: weighted by os_awal
        $targetMigrasiTl = 0.3000;
        if ($sumOsAwal > 0) {
            $targetMigrasiTl = round(
                $scoped->sum(function ($it) {
                    $t = (float)($it->target_migrasi_npl_pct ?? 0.3000);
                    $w = (float)($it->os_kol2_awal ?? 0);
                    return $t * $w;
                }) / $sumOsAwal
            , 4);
        }

        // achievement (nett = rupiah)
        $achOsTurun = $this->pct($sumOsTurunMurni, $sumTargetOsTurun);
        $achPenalty = $this->pct($sumPenalty, $sumPenaltyTarget);
        $achMigrasi = $this->achReverse($migrasiPctTotal, $targetMigrasiTl);

        // score
        $scoreOsTurun = $this->scoreFromAchievement($achOsTurun);
        $scorePenalty = $this->scoreFromAchievement($achPenalty);
        $scoreMigrasi = $this->scoreMigrasiFromPct($migrasiPctTotal);

        // PI
        $piOsTurun = round($scoreOsTurun * ($this->weights['os_turun'] ?? 0), 2);
        $piMigrasi = round($scoreMigrasi * ($this->weights['migrasi'] ?? 0), 2);
        $piPenalty = round($scorePenalty * ($this->weights['penalty'] ?? 0), 2);

        return (object) [
            'name'        => $leader?->name ?? '-',
            'leader_role' => $leaderRole,
            'scope_count' => $scoped->count(),

            // alias (untuk sheet_fe TL recap)
            'target_nett_os_down'       => $sumTargetOsTurun,
            'nett_os_down'              => $sumOsTurunMurni,
            'nett_os_down_total'        => $sumOsTurunTotal,
            'nett_os_down_migrasi'      => $sumOsTurunMigr,
            'nett_os_down_pct_info'     => $sumPctInfo,

            'ach_nett_os_down'          => $achOsTurun,
            'score_nett_os_down'        => $scoreOsTurun,
            'pi_nett_os_down'           => $piOsTurun,

            'target_npl_migration_pct'  => $targetMigrasiTl,
            'npl_migration_pct'         => $migrasiPctTotal,
            'ach_npl_migration'         => $achMigrasi,
            'score_npl_migration'       => $scoreMigrasi,
            'pi_npl_migration'          => $piMigrasi,

            'target_penalty'            => $sumPenaltyTarget,
            'penalty_actual'            => $sumPenalty,
            'ach_penalty'               => $achPenalty,
            'score_penalty'             => $scorePenalty,
            'pi_penalty'                => $piPenalty,

            'pi_total'                  => round($piOsTurun + $piMigrasi + $piPenalty, 2),

            'rankings'                  => $rankings,
        ];
    }
}
