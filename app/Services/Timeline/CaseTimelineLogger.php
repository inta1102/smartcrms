<?php

namespace App\Services\Timeline;

use App\Models\CaseAction;

class CaseTimelineLogger
{
    /**
     * Log timeline (idempotent) untuk NPL Case.
     *
     * Kunci idempotent:
     * - source_system
     * - source_ref_id
     * - action_type
     * - result
     */
    public function logOnce(array $payload, int $dedupeSeconds = 10): ?CaseAction
    {
        $nplCaseId = $payload['npl_case_id'] ?? null;
        if (!$nplCaseId) return null;

        $sourceSystem = (string) ($payload['source_system'] ?? '');
        $sourceRefId  = (int)    ($payload['source_ref_id'] ?? 0);
        $actionType   = (string) ($payload['action_type'] ?? '');
        $result       = (string) ($payload['result'] ?? '');

        if (!$sourceSystem || !$sourceRefId || !$actionType || !$result) {
            // biar strict: jangan log kalau kunci utama tidak lengkap
            return null;
        }

        $exists = CaseAction::where('source_system', $sourceSystem)
            ->where('source_ref_id', $sourceRefId)
            ->where('action_type', $actionType)
            ->where('result', $result)
            ->where('action_at', '>=', now()->subSeconds($dedupeSeconds))
            ->exists();

        if ($exists) return null;

        return CaseAction::create($payload);
    }
}
