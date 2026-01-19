<?php

namespace App\Services\Supervision;

use App\Models\CaseResolutionTarget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class KasiTargetApprovalListService
{
    public function paginate(Request $request): LengthAwarePaginator
    {
        $status   = strtolower(trim((string) $request->query('status', 'pending_kasi')));
        $overSla  = (int) $request->query('over_sla', 0);
        $qSearch  = trim((string) $request->query('q', ''));
        $perPage  = (int) $request->query('per_page', 15);

        $kasiSlaDays = (int) config('supervision.sla_days.kasi_approval', 2);

        $query = CaseResolutionTarget::query()
            ->with([
                'nplCase.loanAccount', // debtor name
                'proposer',            // AO / pengusul
                'approver',            // approver bila ada
            ])
            ->where('is_active', true);

        // Filter status
        if (in_array($status, ['pending_kasi', 'pending_tl', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        // Filter SLA (khusus yang pending)
        if ($overSla === 1) {
            $query->whereIn('status', [
                CaseResolutionTarget::STATUS_PENDING_TL,
                CaseResolutionTarget::STATUS_PENDING_KASI,
            ])->where('created_at', '<', now()->subDays($kasiSlaDays));
        }

        // Search (debtor / strategi / outcome)
        if ($qSearch !== '') {
            $query->where(function ($w) use ($qSearch) {
                $w->where('strategy', 'like', "%{$qSearch}%")
                  ->orWhere('target_outcome', 'like', "%{$qSearch}%")
                  ->orWhereHas('nplCase.loanAccount', function ($qq) use ($qSearch) {
                      $qq->where('customer_name', 'like', "%{$qSearch}%")
                         ->orWhere('customer_no', 'like', "%{$qSearch}%");
                  })
                  ->orWhereHas('proposer', function ($qq) use ($qSearch) {
                      $qq->where('name', 'like', "%{$qSearch}%")
                         ->orWhere('username', 'like', "%{$qSearch}%");
                  });
            });
        }

        // Sorting: yang paling urgent dulu
        // pending_kasi: oldest first
        if ($status === 'pending_kasi' || $overSla === 1) {
            $query->orderBy('created_at', 'asc');
        } else {
            $query->orderBy('updated_at', 'desc');
        }

        return $query->paginate(max(5, min(100, $perPage)))->withQueryString();
    }
}
