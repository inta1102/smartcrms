<?php

namespace App\Http\Controllers\Supervision;

use App\Http\Controllers\Controller;
use App\Models\NonLitigationAction;
use App\Enums\UserRole;
use App\Services\Crms\NonLitApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Supervision\Concerns\EnsureKasiAccess;

use App\Services\Org\OrgVisibilityService;


class NonLitApprovalKasiController extends Controller
{
    use EnsureKasiAccess;
    /** @var array<int, string> */
    private array $kasiRoles = ['KSL','KSO','KSA','KSF','KSD','KSR'];

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('requireRole:' . implode(',', $this->kasiRoles));
    }

    /**
     * Gate kecil biar RBAC controller rapi.
     */
    protected function ensureKasi(): void
    {
        $user = auth()->user();
        abort_unless($user, 403);

        // user->role() kamu return enum UserRole
        abort_unless(
            in_array($user->role(), [
                UserRole::KSL,
                UserRole::KSO,
                UserRole::KSA,
                UserRole::KSF,
                UserRole::KSD,
                UserRole::KSR,
            ], true),
            403
        );
    }

    public function index(Request $request)
    {
        // Pastikan method ini EXIST di controller ini.
        // Kalau yang ada namanya ensureKasiLevel(), ganti pemanggilannya.
        $this->ensureKasi();

        $kasiId = (int) auth()->id();

        /** @var OrgVisibilityService $org */
        $org = app(OrgVisibilityService::class);

        // under KASI: staff direct + TL + staff under TL
        $underIds = method_exists($org, 'subordinateUserIdsForKasi')
            ? (array) $org->subordinateUserIdsForKasi($kasiId)
            : [];

        $q = NonLitigationAction::query()
            // ✅ scope under KASI (ini yang bikin tidak bocor)
            ->when(!empty($underIds), function ($qb) use ($underIds) {
                // 🔥 GANTI kolom ini kalau nonlit bukan proposed_by:
                $qb->whereIn('proposed_by', $underIds);
            }, function ($qb) {
                // kalau under kosong, jangan tampilkan apa-apa (aman)
                $qb->whereRaw('1=0');
            })

            // Inbox Kasi:
            // - status pending_kasi
            // - atau status pending_tl tapi needs_tl_approval = 0 (skip TL)
            ->where(function ($x) {
                $x->where('status', NonLitigationAction::STATUS_PENDING_KASI)
                ->orWhere(function ($y) {
                    $y->where('status', NonLitigationAction::STATUS_PENDING_TL)
                        ->where('needs_tl_approval', 0);
                });
            })

            ->with(['nplCase.loanAccount', 'proposer'])
            ->latest('proposal_at');

        // Search (nama nasabah / no rekening)
        if ($request->filled('q')) {
            $keyword = trim((string) $request->q);

            if ($keyword !== '') {
                $q->whereHas('nplCase.loanAccount', function ($sub) use ($keyword) {
                    $sub->where(function ($w) use ($keyword) {
                        $w->where('customer_name', 'like', "%{$keyword}%")
                        ->orWhere('account_no', 'like', "%{$keyword}%");
                    });
                });
            }
        }

        $items = $q->paginate(15)->withQueryString();

        return view('supervision.approvals.nonlit-kasi', compact('items'));
    }

    public function approve(Request $request, NonLitigationAction $nonLit, NonLitApprovalService $svc)
    {
        $this->ensureKasi();
        $org = app(\App\Services\Org\OrgVisibilityService::class);
        abort_unless(
            $org->isWithinKasiScope((int)auth()->id(), (int)$nonLit->proposed_by), // atau created_by
            403
        );

        // Fail-fast: pastikan status memang valid untuk approve Kasi
        if (!in_array($nonLit->status, [
            NonLitigationAction::STATUS_PENDING_KASI,
            NonLitigationAction::STATUS_PENDING_TL,
        ], true)) {
            return back()->with('error', 'Status Non-Lit tidak valid untuk approval KASI.');
        }

        // Kalau masih pending TL tapi seharusnya butuh TL approval, Kasi tidak boleh approve
        if (
            $nonLit->status === NonLitigationAction::STATUS_PENDING_TL &&
            (int) $nonLit->needs_tl_approval === 1
        ) {
            return back()->with('error', 'Non-Lit masih membutuhkan approval TL terlebih dahulu.');
        }

        $validated = $request->validate([
            'approval_notes'      => ['nullable', 'string', 'max:4000'],
            'effective_date'      => ['nullable', 'date'],
            'monitoring_next_due' => ['nullable', 'date'],
        ]);

        $user = auth()->user();

        DB::transaction(function () use ($nonLit, $svc, $user, $validated) {
            $t = NonLitigationAction::query()
                ->whereKey($nonLit->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-check di dalam lock (anti race condition)
            if (!in_array($t->status, [
                NonLitigationAction::STATUS_PENDING_KASI,
                NonLitigationAction::STATUS_PENDING_TL,
            ], true)) {
                abort(409, 'Status Non-Lit berubah. Silakan refresh halaman.');
            }

            if (
                $t->status === NonLitigationAction::STATUS_PENDING_TL &&
                (int) $t->needs_tl_approval === 1
            ) {
                abort(409, 'Non-Lit masih membutuhkan approval TL.');
            }

            $svc->approveKasi($t, (int) $user->id, $validated);
        });

        return back()->with(
            'success',
            'Non-Lit disetujui KASI → APPROVED. Agenda monitoring dibuat jika due terisi.'
        );
    }

    public function reject(Request $request, NonLitigationAction $nonLit, NonLitApprovalService $svc)
    {
        $this->ensureKasi();
        $org = app(\App\Services\Org\OrgVisibilityService::class);
        abort_unless(
            $org->isWithinKasiScope((int)auth()->id(), (int)$nonLit->proposed_by), // atau created_by
            403
        );

        // Fail-fast: status valid untuk reject Kasi
        if (!in_array($nonLit->status, [
            NonLitigationAction::STATUS_PENDING_KASI,
            NonLitigationAction::STATUS_PENDING_TL,
        ], true)) {
            return back()->with('error', 'Status Non-Lit tidak valid untuk penolakan KASI.');
        }

        if (
            $nonLit->status === NonLitigationAction::STATUS_PENDING_TL &&
            (int) $nonLit->needs_tl_approval === 1
        ) {
            return back()->with('error', 'Non-Lit masih membutuhkan proses TL (tidak bisa ditolak KASI di tahap ini).');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $user = auth()->user();

        DB::transaction(function () use ($nonLit, $svc, $user, $data) {
            $t = NonLitigationAction::query()
                ->whereKey($nonLit->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-check di dalam lock (anti race condition)
            if (!in_array($t->status, [
                NonLitigationAction::STATUS_PENDING_KASI,
                NonLitigationAction::STATUS_PENDING_TL,
            ], true)) {
                abort(409, 'Status Non-Lit berubah. Silakan refresh halaman.');
            }

            if (
                $t->status === NonLitigationAction::STATUS_PENDING_TL &&
                (int) $t->needs_tl_approval === 1
            ) {
                abort(409, 'Non-Lit masih membutuhkan proses TL.');
            }

            $svc->rejectKasi($t, (int) $user->id, (string) $data['reason']);
        });

        return back()->with('success', 'Non-Lit ditolak KASI.');
    }

    protected function applyKasiScope($q, int $kasiId): void
    {
        $org = app(\App\Services\Org\OrgVisibilityService::class);

        $ids = method_exists($org, 'subordinateUserIdsForKasi')
            ? (array) $org->subordinateUserIdsForKasi($kasiId)
            : [];

        if (empty($ids)) {
            $q->whereRaw('1=0');
            return;
        }

        // GANTI kolom ini kalau nonlit bukan proposed_by:
        $q->whereIn('proposed_by', $ids);
    }

}
