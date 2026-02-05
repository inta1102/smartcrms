<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\KpiSoTarget;
use App\Models\OrgAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SoTargetApprovalController extends Controller
{
    // =========================================================
    // INDEX
    // =========================================================
    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $tab = (string) $request->query('tab', 'inbox');
        if (!in_array($tab, ['inbox', 'approved', 'rejected', 'all'], true)) $tab = 'inbox';

        $role = $this->meRole($me);

        $isTl   = $this->isTlRole($role);
        $isKasi = $this->isKasiRole($role);

        abort_unless($isTl || $isKasi, 403);

        $q = KpiSoTarget::query()
            ->with('user')
            ->orderByDesc('period');

        if ($tab === 'inbox') {
            $q->where('status', $isTl ? KpiSoTarget::STATUS_PENDING_TL : KpiSoTarget::STATUS_PENDING_KASI);
        } elseif ($tab === 'approved') {
            $q->where('status', KpiSoTarget::STATUS_APPROVED);
        } elseif ($tab === 'rejected') {
            $q->where('status', KpiSoTarget::STATUS_REJECTED);
        } // all: no filter

        $targets = $q->paginate(20)->withQueryString();

        $inboxLabel = $isTl ? 'Inbox: Pending TL' : 'Inbox: Pending Kasi';

        return view('kpi.so.approvals.index', compact('targets', 'tab', 'inboxLabel'));
    }

    // =========================================================
    // SHOW
    // =========================================================
    public function show(KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $role = $this->meRole($me);
        $isTl   = $this->isTlRole($role);
        $isKasi = $this->isKasiRole($role);

        abort_unless($isTl || $isKasi, 403);

        // status harus sesuai inbox role
        if ($isTl) {
            abort_unless($target->status === KpiSoTarget::STATUS_PENDING_TL, 422);
        } else {
            abort_unless($target->status === KpiSoTarget::STATUS_PENDING_KASI, 422);
        }

        abort_unless($this->isWithinScope((int) $me->id, (int) $target->user_id), 403);

        $target->load('user');

        return view('kpi.so.approvals.show', compact('target'));
    }

    // =========================================================
    // UPDATE (adjust target sebelum approve)
    // =========================================================
    public function update(Request $request, KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $role = $this->meRole($me);
        $isTl   = $this->isTlRole($role);
        $isKasi = $this->isKasiRole($role);
        abort_unless($isTl || $isKasi, 403);

        abort_unless(
            in_array($target->status, [KpiSoTarget::STATUS_PENDING_TL, KpiSoTarget::STATUS_PENDING_KASI], true),
            422
        );
        abort_unless($this->isWithinScope((int)$me->id, (int)$target->user_id), 403);

        $data = $request->validate([
            'target_os_disbursement'  => ['required', 'integer', 'min:0'],
            'target_noa_disbursement' => ['required', 'integer', 'min:0'],
            'target_rr'               => ['nullable', 'numeric', 'min:0', 'max:100'],
            'target_activity'         => ['nullable', 'integer', 'min:0'],

            // ✅ catatan review (disarankan)
            'review_note'             => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($target, $data, $me) {
            $t = KpiSoTarget::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                in_array($t->status, [KpiSoTarget::STATUS_PENDING_TL, KpiSoTarget::STATUS_PENDING_KASI], true),
                422
            );

            // cek apakah ada perubahan angka (kalau berubah, minta note)
            $newOs  = (int)$data['target_os_disbursement'];
            $newNoa = (int)$data['target_noa_disbursement'];
            $newRr  = (float)($data['target_rr'] ?? $t->target_rr ?? 100);
            $newAct = (int)($data['target_activity'] ?? $t->target_activity ?? 0);

            $changed =
                $newOs  !== (int)$t->target_os_disbursement ||
                $newNoa !== (int)$t->target_noa_disbursement ||
                (float)$newRr !== (float)$t->target_rr ||
                $newAct !== (int)$t->target_activity;

            if ($changed) {
                // catatan wajib kalau ada perubahan
                $note = trim((string)($data['review_note'] ?? ''));
                abort_unless($note !== '', 422);
                $t->review_note = $note;
            } else {
                // kalau gak berubah, boleh simpan note kalau diisi
                if (array_key_exists('review_note', $data) && trim((string)$data['review_note']) !== '') {
                    $t->review_note = trim((string)$data['review_note']);
                }
            }

            $t->target_os_disbursement  = $newOs;
            $t->target_noa_disbursement = $newNoa;
            $t->target_rr               = $newRr;
            $t->target_activity         = $newAct;

            $t->save();
        });

        return back()->with('status', 'Target SO diperbarui.');
    }

    // =========================================================
    // APPROVE
    // TL: pending_tl -> pending_kasi (save tl_approved_*)
    // KASI: pending_kasi -> approved (save approved_*)
    // =========================================================
    public function approve(Request $request, KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $role = $this->meRole($me);
        $isTl   = $this->isTlRole($role);
        $isKasi = $this->isKasiRole($role);
        abort_unless($isTl || $isKasi, 403);

        abort_unless($this->isWithinScope((int)$me->id, (int)$target->user_id), 403);

        $data = $request->validate([
            // opsional, tapi boleh dipakai untuk catatan approve
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($me, $target, $isTl, $isKasi, $data) {
            $t = KpiSoTarget::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            // kalau ada note, simpan
            if (array_key_exists('review_note', $data) && trim((string)$data['review_note']) !== '') {
                $t->review_note = trim((string)$data['review_note']);
            }

            if ($isTl) {
                abort_unless($t->status === KpiSoTarget::STATUS_PENDING_TL, 422);

                // ✅ jejak TL
                if (property_exists($t, 'tl_approved_by')) $t->tl_approved_by = (int)$me->id;
                if (property_exists($t, 'tl_approved_at')) $t->tl_approved_at = now();

                $t->status = KpiSoTarget::STATUS_PENDING_KASI;
                $t->save();
                return;
            }

            // ✅ Kasi final approve
            abort_unless($t->status === KpiSoTarget::STATUS_PENDING_KASI, 422);

            $t->status      = KpiSoTarget::STATUS_APPROVED;
            $t->approved_by = (int)$me->id;
            $t->approved_at = now();
            $t->save();
        });

        return redirect()->route('kpi.so.approvals.index', ['tab' => 'inbox'])
            ->with('status', 'Approval berhasil diproses.');
    }

    // =========================================================
    // REJECT
    // TL/Kasi boleh reject sesuai inbox-nya
    // wajib rejected_note
    // =========================================================
    public function reject(Request $request, KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $role = $this->meRole($me);
        $isTl   = $this->isTlRole($role);
        $isKasi = $this->isKasiRole($role);
        abort_unless($isTl || $isKasi, 403);

        abort_unless($this->isWithinScope((int)$me->id, (int)$target->user_id), 403);

        $data = $request->validate([
            'rejected_note' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($me, $target, $isTl, $isKasi, $data) {
            $t = KpiSoTarget::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($isTl)   abort_unless($t->status === KpiSoTarget::STATUS_PENDING_TL, 422);
            if ($isKasi) abort_unless($t->status === KpiSoTarget::STATUS_PENDING_KASI, 422);

            $t->status = KpiSoTarget::STATUS_REJECTED;

            // ✅ jejak reject (bukan approved_by)
            if (property_exists($t, 'rejected_by'))   $t->rejected_by = (int)$me->id;
            if (property_exists($t, 'rejected_at'))   $t->rejected_at = now();
            if (property_exists($t, 'rejected_note')) $t->rejected_note = trim((string)$data['rejected_note']);

            $t->save();
        });

        return redirect()->route('kpi.so.approvals.index', ['tab' => 'inbox'])
            ->with('status', 'Target ditolak.');
    }

    // =========================================================
    // HELPERS
    // =========================================================

    private function meRole($me): string
    {
        // single-source-of-truth: roleValue() kalau ada
        if (method_exists($me, 'roleValue')) {
            return strtoupper(trim((string)$me->roleValue()));
        }

        // fallback: level enum/string
        return strtoupper(trim((string)($me->level instanceof \BackedEnum ? $me->level->value : $me->level)));
    }

    private function isTlRole(string $role): bool
    {
        return in_array($role, ['TL','TLL','TLR','TLF'], true);
    }

    private function isKasiRole(string $role): bool
    {
        // samakan dengan yang kamu pakai di sidebar/index
        return in_array($role, ['KSL','KSR','KSO','KSA','KSF','KSD','KBL'], true);
    }

    private function isWithinScope(int $leaderId, int $staffId): bool
    {
        return OrgAssignment::query()
            ->active()
            ->where('leader_id', $leaderId)
            ->where('user_id', $staffId)
            ->exists();
    }
}
