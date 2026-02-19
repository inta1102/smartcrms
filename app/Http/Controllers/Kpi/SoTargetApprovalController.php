<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\KpiSoTarget;
use App\Models\OrgAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SoTargetApprovalController extends Controller
{
    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $tab = (string) $request->query('tab', 'inbox');
        if (!in_array($tab, ['inbox','approved','rejected','all'], true)) $tab = 'inbox';

        $meRole = strtoupper(trim((string)($me->roleValue() ?? '')));

        $isTl   = in_array($meRole, ['TL','TLL','TLR','TLF','TLRO','TLSO','TLFE','TLBE','TLUM'], true);
        $isKasi = in_array($meRole, ['KSLU','KSLR','KSFE','KSBE','KSR','KSO','KSA','KSF','KSD','KBL'], true);

        abort_unless($isTl || $isKasi, 403);

        // scope bawahan (user_id staff)
        $subIds = $this->subordinateUserIds((int)$me->id);

        $q = KpiSoTarget::query()
            ->with('user')
            ->when(!empty($subIds), fn($qq) => $qq->whereIn('user_id', $subIds))
            ->orderByDesc('period');

        if ($tab === 'inbox') {
            $q->where('status', $isTl ? KpiSoTarget::STATUS_PENDING_TL : KpiSoTarget::STATUS_PENDING_KASI);
        } elseif ($tab === 'approved') {
            $q->where('status', KpiSoTarget::STATUS_APPROVED);
        } elseif ($tab === 'rejected') {
            $q->where('status', KpiSoTarget::STATUS_REJECTED);
        } // all = no status filter

        $targets = $q->paginate(20)->withQueryString();
        $inboxLabel = $isTl ? 'Inbox: Pending TL' : 'Inbox: Pending Kasi';

        return view('kpi.so.approvals.index', compact('targets','tab','inboxLabel'));
    }

    public function show(KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $isTl   = $this->isTl($me);
        $isKasi = $this->isKasi($me);
        abort_unless($isTl || $isKasi, 403);

        // status harus sesuai inbox masing-masing
        if ($isTl)   abort_unless($target->status === KpiSoTarget::STATUS_PENDING_TL, 422);
        if ($isKasi) abort_unless($target->status === KpiSoTarget::STATUS_PENDING_KASI, 422);

        abort_unless($this->isWithinScope((int)$me->id, (int)$target->user_id), 403);

        $target->load('user');

        return view('kpi.so.approvals.show', compact('target'));
    }

    public function update(Request $request, KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $isTl   = $this->isTl($me);
        $isKasi = $this->isKasi($me);
        abort_unless($isTl || $isKasi, 403);

        abort_unless(in_array($target->status, [
            KpiSoTarget::STATUS_PENDING_TL,
            KpiSoTarget::STATUS_PENDING_KASI,
        ], true), 422);

        abort_unless($this->isWithinScope((int)$me->id, (int)$target->user_id), 403);

        $data = $request->validate([
            'target_os_disbursement'  => ['required','integer','min:0'],
            'target_noa_disbursement' => ['required','integer','min:0'],
            'target_rr'               => ['nullable','numeric','min:0','max:100'],
            'target_activity'         => ['nullable','integer','min:0'],
        ]);

        $target->update([
            'target_os_disbursement'  => (int)$data['target_os_disbursement'],
            'target_noa_disbursement' => (int)$data['target_noa_disbursement'],
            'target_rr'               => (float)($data['target_rr'] ?? $target->target_rr ?? 100),
            'target_activity'         => (int)($data['target_activity'] ?? $target->target_activity ?? 0),
        ]);

        return back()->with('status', 'Target SO diperbarui.');
    }

    public function approve(Request $request, KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $isTl   = $this->isTl($me);
        $isKasi = $this->isKasi($me);
        abort_unless($isTl || $isKasi, 403);

        abort_unless($this->isWithinScope((int)$me->id, (int)$target->user_id), 403);

        DB::transaction(function () use ($me, $target, $isTl) {
            $t = KpiSoTarget::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($isTl) {
                abort_unless($t->status === KpiSoTarget::STATUS_PENDING_TL, 422);
                $t->status = KpiSoTarget::STATUS_PENDING_KASI;
                $t->save();
                return;
            }

            abort_unless($t->status === KpiSoTarget::STATUS_PENDING_KASI, 422);
            $t->status = KpiSoTarget::STATUS_APPROVED;
            $t->approved_by = (int)$me->id;
            $t->approved_at = now();
            $t->save();
        });

        return redirect()->route('kpi.so.approvals.index')->with('status', 'Approval berhasil diproses.');
    }

    public function reject(Request $request, KpiSoTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $isTl   = $this->isTl($me);
        $isKasi = $this->isKasi($me);
        abort_unless($isTl || $isKasi, 403);

        abort_unless($this->isWithinScope((int)$me->id, (int)$target->user_id), 403);

        DB::transaction(function () use ($me, $target, $isTl) {
            $t = KpiSoTarget::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($isTl) {
                abort_unless($t->status === KpiSoTarget::STATUS_PENDING_TL, 422);
            } else {
                abort_unless($t->status === KpiSoTarget::STATUS_PENDING_KASI, 422);
            }

            $t->status = KpiSoTarget::STATUS_REJECTED;
            $t->approved_by = (int)$me->id;
            $t->approved_at = now();
            $t->save();
        });

        return redirect()->route('kpi.so.approvals.index')->with('status', 'Target ditolak.');
    }

    // ========================= helpers =========================

    private function isTl($me): bool
    {
        $roleValue = strtoupper(trim((string)($me->roleValue() ?? '')));
        return in_array($roleValue, ['TL','TLL','TLR','TLF','TLRO','TLSO','TLFE','TLBE','TLUM'], true);
    }

    private function isKasi($me): bool
    {
        $roleValue = strtoupper(trim((string)($me->roleValue() ?? '')));
        return in_array($roleValue, ['KSLU','KSLR','KSFE','KSBE','KSR','KSO','KSA','KSF','KSD','KBL'], true);
    }

    private function subordinateUserIds(int $leaderId): array
    {
        return OrgAssignment::query()
            ->active()
            ->where('leader_id', $leaderId)   // ✅ sesuai struktur tabelmu
            ->pluck('user_id')
            ->map(fn($v) => (int) $v)
            ->all();
    }

    private function isWithinScope(int $leaderId, int $staffId): bool
    {
        return OrgAssignment::query()
            ->active()
            ->where('leader_id', $leaderId)  // ✅ sesuai struktur tabelmu
            ->where('user_id', $staffId)
            ->exists();
    }
}
