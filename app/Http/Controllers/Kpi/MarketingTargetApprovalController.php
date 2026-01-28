<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\MarketingKpiTarget;
use App\Models\OrgAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketingTargetApprovalController extends Controller
{
    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $lvl = $this->levelCode($me);

        $isTl   = in_array($lvl, ['TL','TLL','TLR','TLF'], true);
        $isKasi = in_array($lvl, ['KSL','KSO','KSA','KSF','KSD','KSR'], true);
        abort_unless($isTl || $isKasi, 403);

        // ===== status (inbox per role) =====
        $defaultStatus = $isTl
            ? MarketingKpiTarget::STATUS_PENDING_TL
            : MarketingKpiTarget::STATUS_PENDING_KASI;

        $status = strtoupper(trim((string) $request->get('status', $defaultStatus)));

        // batasi status yg boleh dilihat sesuai role
        if ($isTl) {
            $status = MarketingKpiTarget::STATUS_PENDING_TL; // TL hanya inbox TL
        } else {
            $status = MarketingKpiTarget::STATUS_PENDING_KASI; // KASI hanya inbox KASI
        }

        // ===== period filter =====
        $period = trim((string) $request->get('period', ''));
        if ($period !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $period)) {
            $period = '';
        }

        $userIds = $this->approvalScopeUserIds($me);

        $q = MarketingKpiTarget::query()
            ->with(['user'])
            ->whereIn('user_id', $userIds)
            ->where('status', $status)
            ->where('is_locked', 0);

        if ($period !== '') {
            $q->whereDate('period', $period);
        }

        $targets = $q->orderByDesc('period')->paginate(20)->withQueryString();

        $periodOptions = $this->periodOptionsForApprover($me, $status);

        return view('kpi.marketing.approvals.index', compact(
            'targets', 'status', 'period', 'periodOptions'
        ));
    }

    public function show(MarketingKpiTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        abort_unless(in_array((int) $target->user_id, $this->approvalScopeUserIds($me), true), 403);

        $target->load(['user']);

        return view('kpi.marketing.approvals.show', compact('target'));
    }

    public function update(Request $request, MarketingKpiTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        abort_unless(in_array((int) $target->user_id, $this->approvalScopeUserIds($me), true), 403);

        abort_unless(!$target->is_locked, 422);
        abort_unless(in_array($target->status, [
            MarketingKpiTarget::STATUS_PENDING_TL,
            MarketingKpiTarget::STATUS_PENDING_KASI,
        ], true), 422);

        $data = $request->validate([
            'target_os_growth' => ['required','numeric','min:0'],
            'target_noa'       => ['required','integer','min:0'],
            'notes'            => ['nullable','string','max:500'],
        ]);

        $target->update($data);

        return back()->with('status', 'Perubahan target tersimpan (belum approve).');
    }

    /**
     * APPROVE:
     * - TL: PENDING_TL -> PENDING_KASI
     * - KASI: PENDING_KASI -> APPROVED + LOCK
     */
    public function approve(Request $request, MarketingKpiTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        abort_unless(in_array((int) $target->user_id, $this->approvalScopeUserIds($me), true), 403);

        DB::transaction(function () use ($me, $target) {
            $t = MarketingKpiTarget::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(!$t->is_locked, 422);

            $lvl = $this->levelCode($me);

            $isTl   = in_array($lvl, ['TL','TLL','TLR','TLF'], true);
            $isKasi = in_array($lvl, ['KSL','KSO','KSA','KSF','KSD','KSR'], true);

            if ($isTl) {
                abort_unless($t->status === MarketingKpiTarget::STATUS_PENDING_TL, 422);

                $t->status = MarketingKpiTarget::STATUS_PENDING_KASI;

                $stamp = now()->format('Y-m-d H:i');
                $t->notes = trim(($t->notes ? $t->notes . "\n" : '') . "TL APPROVED by {$me->name} ({$me->id}) @ {$stamp}");
                $t->save();
                return;
            }

            if ($isKasi) {
                abort_unless($t->status === MarketingKpiTarget::STATUS_PENDING_KASI, 422);

                $t->status      = MarketingKpiTarget::STATUS_APPROVED;
                $t->approved_by = $me->id;
                $t->approved_at = now();
                $t->is_locked   = 1;

                $stamp = now()->format('Y-m-d H:i');
                $t->notes = trim(($t->notes ? $t->notes . "\n" : '') . "KASI APPROVED by {$me->name} ({$me->id}) @ {$stamp}");

                $t->save();
                return;
            }

            abort(403);
        });

        return redirect()->route('kpi.marketing.approvals.index')
            ->with('status', 'Approval berhasil diproses.');
    }

    public function reject(Request $request, MarketingKpiTarget $target)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        abort_unless(in_array((int) $target->user_id, $this->approvalScopeUserIds($me), true), 403);

        $data = $request->validate([
            'reject_note' => ['required','string','max:500'],
        ]);

        DB::transaction(function () use ($me, $target, $data) {
            $t = MarketingKpiTarget::query()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(!$t->is_locked, 422);

            abort_unless(in_array($t->status, [
                MarketingKpiTarget::STATUS_PENDING_TL,
                MarketingKpiTarget::STATUS_PENDING_KASI,
            ], true), 422);

            $t->status      = MarketingKpiTarget::STATUS_REJECTED;
            $t->approved_by = $me->id;
            $t->approved_at = now();
            $t->is_locked   = 0;

            $t->notes = trim(($t->notes ? $t->notes . "\n" : '') . 'REJECT: ' . $data['reject_note']);
            $t->save();
        });

        return redirect()->route('kpi.marketing.approvals.index')
            ->with('status', 'Target ditolak. AO bisa perbaiki & submit ulang.');
    }

    /**
     * Scope user_id untuk inbox approval, mengikuti OrgAssignment:
     * - TL: bawahan langsung (leader_id = me)
     * - KASI: bawahan langsung + level-2 dari TL yang melapor ke Kasi
     */
    protected function approvalScopeUserIds(User $me): array
    {
        $selfId = (int) $me->id;
        $lvl = $this->levelCode($me);

        $base = OrgAssignment::query()->active();

        if (in_array($lvl, ['TL','TLL','TLR','TLF'], true)) {
            return (clone $base)
                ->where('leader_id', $selfId)
                ->pluck('user_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values()
                ->all();
        }

        if (in_array($lvl, ['KSL','KSO','KSA','KSF','KSD','KSR'], true)) {

            $directIds = (clone $base)
                ->where('leader_id', $selfId)
                ->pluck('user_id')
                ->map(fn ($v) => (int) $v);

            // ambil yang TL dari directIds
            $tlIds = User::query()
                ->whereIn('id', $directIds->all())
                ->whereIn('level', ['TL','TLL','TLR','TLF'])
                ->pluck('id')
                ->map(fn ($v) => (int) $v);

            $level2Ids = (clone $base)
                ->whereIn('leader_id', $tlIds->all())
                ->pluck('user_id')
                ->map(fn ($v) => (int) $v);

            return $directIds
                ->merge($level2Ids)
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }

    protected function periodOptionsForApprover(User $me, string $status): array
    {
        $userIds = $this->approvalScopeUserIds($me);

        return MarketingKpiTarget::query()
            ->whereIn('user_id', $userIds)
            ->where('status', $status)
            ->where('is_locked', 0)
            ->select('period')
            ->distinct()
            ->orderByDesc('period')
            ->pluck('period')
            ->map(fn ($d) => \Carbon\Carbon::parse($d)->startOfMonth()->toDateString())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Ambil kode level aman untuk string / enum (BackedEnum & UnitEnum).
     */
    private function levelCode(User $u): string
    {
        $v = $u->level ?? '';

        if ($v instanceof \BackedEnum) {
            return strtoupper((string) $v->value);
        }

        if ($v instanceof \UnitEnum) {
            return strtoupper((string) $v->name);
        }

        return strtoupper(trim((string) $v));
    }
}
