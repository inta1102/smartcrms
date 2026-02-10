<?php

namespace App\Http\Controllers\Supervision;

use App\Http\Controllers\Controller;
use App\Models\NonLitigationAction;
use App\Enums\UserRole;
use App\Services\Crms\NonLitApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NonLitApprovalTlController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('requireRole:TL,TLL,TLF,TLR,TLRO,TLSO,TLFE,TLBE,TLUM');
    }

    public function index(Request $request)
    {
        $user = auth()->user();

        abort_unless(
            in_array($user->role(), [UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR, UserRole::TLRO, UserRole::TLSO, UserRole::TLFE, UserRole::TLBE, UserRole::TLUM], true),
            403
        );

        $q = NonLitigationAction::query()
            ->where('status', NonLitigationAction::STATUS_PENDING_TL)
            ->where('needs_tl_approval', 1)
            ->with(['nplCase.loanAccount', 'proposer'])
            ->orderBy('proposal_at');

        if ($request->filled('q')) {
            $keyword = trim($request->q);
            $q->whereHas('nplCase.loanAccount', function ($sub) use ($keyword) {
                $sub->where('customer_name', 'like', "%{$keyword}%")
                    ->orWhere('account_no', 'like', "%{$keyword}%");
            });
        }

        $items = $q->paginate(15)->withQueryString();
        return view('supervision.approvals.nonlit-tl', compact('items'));
    }

    public function approve(Request $request, NonLitigationAction $nonLit, NonLitApprovalService $svc)
    {
        $user = auth()->user();

        abort_unless(
            in_array($user->role(), [UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR, UserRole::TLRO, UserRole::TLSO, UserRole::TLFE, UserRole::TLBE, UserRole::TLUM], true),
            403
        );

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($nonLit, $svc, $user, $data) {
            $t = NonLitigationAction::whereKey($nonLit->id)->lockForUpdate()->firstOrFail();
            $svc->approveTl($t, (int)$user->id, $data['notes'] ?? null);
        });

        return back()->with('success', 'Non-Lit disetujui TL → diteruskan ke KASI.');
    }

    public function reject(Request $request, NonLitigationAction $nonLit, NonLitApprovalService $svc)
    {
        $user = auth()->user();

        abort_unless(
            in_array($user->role(), [UserRole::TL, UserRole::TLL, UserRole::TLF, UserRole::TLR, UserRole::TLRO, UserRole::TLSO, UserRole::TLFE, UserRole::TLBE, UserRole::TLUM], true),
            403
        );

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($nonLit, $svc, $user, $data) {
            $t = NonLitigationAction::whereKey($nonLit->id)->lockForUpdate()->firstOrFail();
            $svc->rejectTl($t, (int)$user->id, (string)$data['reason']);
        });

        return back()->with('success', 'Non-Lit ditolak TL.');
    }
}
