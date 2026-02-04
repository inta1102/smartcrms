<?php

namespace App\Http\Controllers\Supervision;

use App\Http\Controllers\Controller;
use App\Models\CaseResolutionTarget;
use App\Models\OrgAssignment;
use App\Services\Crms\ResolutionTargetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Enums\UserRole;

class TargetApprovalTlController extends Controller
{
    protected function ensureTlLevel(): void
    {
        $u = auth()->user();
        abort_unless($u, 403);

        // ✅ single-source-of-truth: enum role
        $role = method_exists($u, 'role') ? $u->role() : null; // UserRole|null
        $val  = method_exists($u, 'roleValue') ? strtoupper((string) $u->roleValue()) : '';

        // TL group: TL/TLL/TLF/TLR
        $isTl = ($role instanceof UserRole)
            ? in_array($role, [UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR], true)
            : in_array($val, ['TL','TLL','TLF','TLR'], true);

        abort_unless($isTl, 403);
    }

    /**
     * ✅ Hardening utama: TL hanya boleh lihat/approve target yg dibuat oleh staff dalam tim TL tsb.
     * Dengan org_assignments:
     * - staff_user_id = proposed_by (user pembuat target)
     * - leader_id     = TL (user yg login)
     * - leader_role   = 'TL' (atau variasi bila kamu simpan spesifik)
     * - is_active     = 1
     */
    protected function assertWithinTlScope(int $proposedByUserId): void
    {
        $tlId = (int) auth()->id();
        abort_unless($tlId > 0, 403);

        // ✅ kalau TL bikin target sendiri (opsional, tapi aman)
        if ($proposedByUserId === $tlId) return;

        $ok = OrgAssignment::query()
            ->where('staff_user_id', $proposedByUserId)
            ->where('leader_id', $tlId)
            ->where('is_active', 1)
            ->whereIn('leader_role', ['TL', 'TLL', 'TLF', 'TLR']) // kalau di DB kamu cuma simpan 'TL', boleh jadi ['TL'] saja
            ->exists();

        abort_unless($ok, 403);
    }

    public function index(Request $request)
    {
        $this->ensureTlLevel();

        $status  = trim((string) $request->input('status', CaseResolutionTarget::STATUS_PENDING_TL));
        $perPage = max(5, (int) $request->input('per_page', 20));
        $kw      = trim((string) $request->input('q', ''));
        $overSla = (int) $request->input('over_sla', 0) === 1;

        $q = CaseResolutionTarget::query()
            ->with(['nplCase.loanAccount', 'proposer']);

        // ✅ status filter (default pending_tl)
        if ($status !== '') {
            $q->where('status', $status);
        }

        // ✅ WAJIB scope TL: hanya target dari bawahan TL tsb (atau milik sendiri)
        $tlId = (int) auth()->id();

        $q->where(function ($w) use ($tlId) {
            $w->where('proposed_by', $tlId)
            ->orWhereHas('proposer.orgAssignmentsAsStaff', function ($x) use ($tlId) {
                $x->active()
                    ->where('leader_id', $tlId)
                    ->whereIn('leader_role', ['TL','TLL','TLF','TLR']);
            });
        });


        // ✅ search (group OR dalam closure)
        if ($kw !== '') {
            $q->where(function ($w) use ($kw) {
                $w->whereHas('nplCase.loanAccount', fn($x) => $x->where('customer_name', 'like', "%{$kw}%"))
                  ->orWhereHas('nplCase', fn($x) => $x->where('debtor_name', 'like', "%{$kw}%"))
                  ->orWhere('strategy', 'like', "%{$kw}%");
            });
        }

        // ✅ SLA pending TL (pakai created_at sementara)
        $tlSlaDays = (int) config('crms.sla.tl_days', 1);
        if ($overSla) {
            $q->where('created_at', '<', now()->subDays($tlSlaDays));
        }

        $targets = $q->orderBy('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $filters = [
            'status'   => $status,
            'q'        => $kw,
            'per_page' => $perPage,
            'over_sla' => $overSla ? 1 : 0,
        ];

        return view('supervision.tl.approvals.targets.index', compact('targets', 'filters', 'tlSlaDays'));
    }

    public function approve(Request $request, CaseResolutionTarget $target, ResolutionTargetService $svc)
    {
        $this->ensureTlLevel();

        $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($target, $svc, $request) {
            $t = CaseResolutionTarget::whereKey($target->id)->lockForUpdate()->firstOrFail();

            // ✅ Anti-bocor: policy scope check (setelah lock)
            $this->authorize('approveTl', $t);

            abort_unless($t->status === CaseResolutionTarget::STATUS_PENDING_TL, 422);

            $svc->approveTl($t, auth()->id(), $request->input('notes'));
        });

        return back()->with('success', 'Target disetujui TL → masuk antrian KASI.');
    }

    public function reject(Request $request, CaseResolutionTarget $target, ResolutionTargetService $svc)
    {
        $this->ensureTlLevel();

        $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($target, $svc, $request) {
            $t = CaseResolutionTarget::whereKey($target->id)->lockForUpdate()->firstOrFail();

            // ✅ Anti-bocor
            $this->authorize('approveTl', $t); // boleh reuse approveTl untuk reject TL, atau bikin rejectTl kalau mau rapi

            abort_unless($t->status === CaseResolutionTarget::STATUS_PENDING_TL, 422);

            $svc->reject($t, auth()->id(), $request->input('reason'));
        });

        return back()->with('success', 'Target ditolak TL.');
    }
}
