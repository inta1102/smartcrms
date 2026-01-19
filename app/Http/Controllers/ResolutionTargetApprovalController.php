<?php

namespace App\Http\Controllers;

use App\Models\CaseResolutionTarget;
use App\Services\Crms\ResolutionTargetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ResolutionTargetApprovalController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function approveTl(Request $request, CaseResolutionTarget $target, ResolutionTargetService $svc)
    {
        $this->authorize('approveTl', $target);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $notes = trim((string)($data['notes'] ?? ''));
        if ($notes === '') $notes = null;

        DB::transaction(function () use ($svc, $target, $notes) {
            $svc->approveTl(
                target: $target,
                byUserId: auth()->id(),
                notes: $notes
            );
        });

        return back()->with('success', 'Target disetujui TL (menunggu approval Kasi).');
    }

    public function approveKasi(Request $request, CaseResolutionTarget $target, ResolutionTargetService $svc)
    {
        $this->authorize('approveKasi', $target);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $notes = trim((string)($data['notes'] ?? ''));
        if ($notes === '') $notes = null;

        DB::transaction(function () use ($svc, $target, $notes) {
            $svc->approveKasi($target, auth()->id(), $notes);
        });

        return back()->with('success', 'Target disetujui Kasi dan menjadi target aktif.');
    }

    public function reject(Request $request, CaseResolutionTarget $target, ResolutionTargetService $svc)
    {
        $this->authorize('reject', $target);

        $data = $request->validate([
            'reject_reason' => ['required', 'string', 'max:500'],
        ]);

        $reason = trim((string)$data['reject_reason']);

        DB::transaction(function () use ($svc, $target, $reason) {
            $svc->reject(
                target: $target,
                byUserId: auth()->id(),
                reason: $reason
            );
        });

        return back()->with('success', '❌ Target ditolak.');
    }
}
