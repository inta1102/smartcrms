<?php

namespace App\Http\Controllers\Supervision;

use App\Http\Controllers\Controller;
use App\Models\CaseResolutionTarget;
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

        // ✅ (OPSIONAL) scope TL: hanya lihat target dari tim TL tersebut
        // kalau struktur org_assignments sudah dipakai, aktifkan ini
        //
        // $tlId = auth()->id();
        // $q->whereHas('proposer.orgAssignmentsAsStaff', function($x) use ($tlId) {
        //     $x->where('leader_id', $tlId)
        //       ->where('is_active', 1)
        //       ->where('leader_role', 'TL');
        // });

        // ✅ search (FIX: group OR dalam closure)
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

        // urutkan dari paling lama pending dulu
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

            // Guard: harus pending TL
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

            // Guard: harus pending TL
            abort_unless($t->status === CaseResolutionTarget::STATUS_PENDING_TL, 422);

            $svc->reject($t, auth()->id(), $request->input('reason'));
        });

        return back()->with('success', 'Target ditolak TL.');
    }
}
