<?php

namespace App\Observers;

use App\Models\HtAuction;
use App\Services\Timeline\CaseTimelineLogger;

class HtAuctionObserver
{
    public function created(HtAuction $auction): void
    {
        $this->logAuction($auction, 'created');
    }

    public function updated(HtAuction $auction): void
    {
        // Log hanya jika field penting berubah
        if ($auction->wasChanged(['auction_result', 'sold_value', 'auction_date'])) {
            $this->logAuction($auction, 'updated');
        }
    }

    private function logAuction(HtAuction $auction, string $mode): void
    {
        $action = $auction->legalAction; // pastikan relasi ada
        $nplCaseId = $action?->legalCase?->npl_case_id;

        if (!$action || !$nplCaseId) return;

        $attempt = (int) ($auction->attempt_no ?? 1);
        $date    = $auction->auction_date ?? now();
        $result  = strtolower((string) ($auction->auction_result ?? 'scheduled')); // scheduled/not_sold/laku

        $resultLabel = match ($result) {
            'laku', 'sold' => 'LAKU',
            'tidak_laku', 'not_sold' => 'TIDAK LAKU',
            'scheduled' => 'DIJADWALKAN',
            default => strtoupper($result),
        };

        $soldValue = $auction->sold_value ? number_format((float) $auction->sold_value,0,',','.') : null;

        $desc = "LEGAL HT: Lelang Attempt #{$attempt} — {$resultLabel}";
        if ($soldValue) $desc .= " (Rp {$soldValue})";

        app(CaseTimelineLogger::class)->logOnce([
            'npl_case_id'   => $nplCaseId,
            'user_id'       => auth()->id(),
            'source_system' => 'legal_ht_auction',
            'source_ref_id' => $auction->id,
            'action_at'     => $date,
            'action_type'   => 'legal',
            'description'   => $desc,
            'result'        => "auction_{$result}",

            'next_action'     => null,
            'next_action_due' => null,

            'meta' => [
                'legal_action_id' => $action->id,
                'legal_case_id'   => $action->legal_case_id,
                'action_type'     => $action->action_type,
                'attempt_no'      => $attempt,
                'auction_result'  => $result,
                'sold_value'      => $auction->sold_value,
                'auction_date'    => (string) $date,
                'mode'            => $mode,
            ],
        ]);
    }
}
