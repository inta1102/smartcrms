<?php

namespace App\Observers;

use App\Models\HtUnderhandSale;
use App\Services\Timeline\CaseTimelineLogger;

class HtUnderhandSaleObserver
{
    public function created(HtUnderhandSale $sale): void
    {
        $this->logSale($sale, 'created');
    }

    public function updated(HtUnderhandSale $sale): void
    {
        if ($sale->wasChanged(['sale_value','deal_date','buyer_name','status'])) {
            $this->logSale($sale, 'updated');
        }
    }

    private function logSale(HtUnderhandSale $sale, string $mode): void
    {
        $action = $sale->legalAction;
        $nplCaseId = $action?->legalCase?->npl_case_id;
        if (!$action || !$nplCaseId) return;

        $value = $sale->sale_value ? number_format((float)$sale->sale_value,0,',','.') : null;
        $buyer = trim((string) ($sale->buyer_name ?? ''));
        $date  = $sale->deal_date ?? now();
        $st    = strtolower((string) ($sale->status ?? 'deal')); // deal/paid/cancelled dsb

        $desc = "LEGAL HT: Penjualan bawah tangan";
        if ($buyer) $desc .= " — Buyer: {$buyer}";
        if ($value) $desc .= " (Rp {$value})";

        app(CaseTimelineLogger::class)->logOnce([
            'npl_case_id'   => $nplCaseId,
            'user_id'       => auth()->id(),
            'source_system' => 'legal_ht_underhand',
            'source_ref_id' => $sale->id,
            'action_at'     => $date,
            'action_type'   => 'legal',
            'description'   => $desc,
            'result'        => "underhand_{$st}",
            'meta' => [
                'legal_action_id' => $action->id,
                'legal_case_id'   => $action->legal_case_id,
                'action_type'     => $action->action_type,
                'sale_value'      => $sale->sale_value,
                'buyer_name'      => $buyer,
                'deal_date'       => (string) $date,
                'status'          => $st,
                'mode'            => $mode,
            ],
        ]);
    }
}
