<?php

namespace App\Observers;

use App\Models\HtDocument;
use App\Services\Timeline\CaseTimelineLogger;

class HtDocumentObserver
{
    public function updated(HtDocument $doc): void
    {
        if (!$doc->wasChanged('status')) return;

        $new = strtolower((string) $doc->status);
        if (!in_array($new, ['verified','rejected'], true)) return;

        $action = $doc->legalAction;
        $nplCaseId = $action?->legalCase?->npl_case_id;
        if (!$action || !$nplCaseId) return;

        $docName = trim((string) ($doc->doc_name ?? $doc->title ?? 'Dokumen'));
        $tag = $new === 'verified' ? 'TERVERIFIKASI' : 'DITOLAK';

        $desc = "LEGAL HT: Dokumen {$tag} — {$docName}";

        app(CaseTimelineLogger::class)->logOnce([
            'npl_case_id'   => $nplCaseId,
            'user_id'       => auth()->id(),
            'source_system' => 'legal_ht_doc',
            'source_ref_id' => $doc->id,
            'action_at'     => now(),
            'action_type'   => 'legal',
            'description'   => $desc,
            'result'        => "doc_{$new}",
            'meta' => [
                'legal_action_id' => $action->id,
                'legal_case_id'   => $action->legal_case_id,
                'action_type'     => $action->action_type,
                'doc_id'          => $doc->id,
                'doc_name'        => $docName,
                'status'          => $new,
            ],
        ]);
    }
}
