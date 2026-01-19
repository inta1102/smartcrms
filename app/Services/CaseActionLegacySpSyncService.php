<?php

namespace App\Services;

use App\Models\CaseAction;
use App\Models\NplCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Arr;

class CaseActionLegacySpSyncService
{
    public function __construct(
        protected LegacySpClient $legacy,
    ) {}

    public function syncForCase(NplCase $case): array
    {
        $case->loadMissing('loanAccount');
        $loan = $case->loanAccount;

        $accountNo = trim((string)(
            ($loan->account_no ?? null)
            ?? ($loan->no_rekening ?? null)
            ?? ''
        ));

        if ($accountNo === '') {
            Log::warning('[CRMS][LEGACY-SP-SYNC-FAILED]', [
                'case_id' => $case->id,
                'message' => 'Missing account_no/no_rekening',
            ]);

            return [
                'ok' => false,
                'fingerprint' => null,
                'changed' => 0,
                'skipped' => 0,
            ];
        }

        $afterId    = $this->getLastLegacyId($case);
        $candidates = $this->legacyAccountCandidates($accountNo) ?: [$accountNo];

        Log::info('[LEGACY SYNC] START', [
            'case_id'    => $case->id,
            'account_no' => $accountNo,
            'after_id'   => $afterId,
        ]);

        try {
            $resp = $this->legacy->lettersByNoRekeningIn($candidates, $afterId);
            $rows = is_array($resp['data'] ?? null) ? $resp['data'] : [];
        } catch (\Throwable $e) {
            Log::error('[LEGACY SYNC] CALL FAILED', [
                'case_id' => $case->id,
                'error'   => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'fingerprint' => null,
                'changed' => 0,
                'skipped' => 0,
            ];
        }

        $rows = $this->dedupeLegacyRowsById($rows);

        $changed = 0;
        $skipped = 0;
        $rowHashes = [];

        foreach ($rows as $legacyRow) {
            $legacyId = (string)($legacyRow['legacy_id'] ?? $legacyRow['id'] ?? '');
            if ($legacyId === '' || !ctype_digit($legacyId)) {
                $skipped++;
                continue;
            }

            $rawType = (string)($legacyRow['type'] ?? '');
            $type    = $this->normalizeSpType($rawType);
            if (!$type) {
                $skipped++;
                continue;
            }

            try {
                $actionAt = Carbon::parse(
                    $legacyRow['issued_at']
                    ?? $legacyRow['tgl_sp']
                    ?? $legacyRow['created_at']
                    ?? now()
                );
            } catch (\Throwable $e) {
                $actionAt = now();
            }

            $hasProof = (bool)($legacyRow['has_proof'] ?? false);

            // 🔑 hash per legacy row
            $rowHash = hash('sha256', json_encode([
                'legacy_id' => (int)$legacyId,
                'type'      => $type,
                'action_at' => $actionAt->toISOString(),
                'has_proof' => $hasProof ? 1 : 0,
            ]));

            $rowHashes[] = $rowHash;

            $existing = CaseAction::where([
                'npl_case_id'   => $case->id,
                'source_system' => 'legacy_sp',
                'source_ref_id' => $legacyId,
            ])->first();

            if ($existing) {
                $oldHash = data_get($existing->meta, 'row_hash');
                if ($oldHash === $rowHash) {
                    continue; // ⛔ benar-benar sama → skip
                }
            }

            CaseAction::updateOrCreate(
                [
                    'npl_case_id'   => $case->id,
                    'source_system' => 'legacy_sp',
                    'source_ref_id' => $legacyId,
                ],
                [
                    'action_at'   => $actionAt,
                    'action_type' => $type,
                    'description'=> 'Imported from Legacy SP',
                    'result'      => $hasProof ? 'proof_available' : 'proof_missing',
                    'meta'        => [
                        'row_hash'    => $rowHash,
                        'legacy_id'   => (int)$legacyId,
                        'legacy_type' => $rawType,
                        'has_proof'   => $hasProof,
                        'raw'         => $legacyRow,
                    ],
                ]
            );

            $changed++;
        }

        // 🔐 fingerprint case (urutkan agar stabil)
        sort($rowHashes);
        $fingerprint = hash('sha256', json_encode($rowHashes));

        Log::info('[LEGACY SYNC] DONE', [
            'case_id'     => $case->id,
            'changed'     => $changed,
            'skipped'     => $skipped,
            'fingerprint' => $fingerprint,
        ]);

        return [
            'ok' => true,
            'case_id' => $case->id,
            'changed' => $changed,
            'skipped' => $skipped,
            'fingerprint' => $fingerprint,
        ];
    }

    private function legacyAccountCandidates(string $accountNo): array
    {
        $digits = preg_replace('/\D+/', '', $accountNo);
        $digits = trim((string)$digits);
        if ($digits === '') return [];

        $cands = [];
        $cands[] = $digits;

        $trim = ltrim($digits, '0');
        if ($trim !== '' && $trim !== $digits) {
            $cands[] = $trim;
        }

        if (strlen($digits) > 12) {
            $cands[] = substr($digits, -12);
        }

        return array_values(array_unique($cands));
    }

    private function dedupeLegacyRowsById(array $rows): array
    {
        $seen = [];
        $out  = [];

        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $id = (string)($r['legacy_id'] ?? $r['id'] ?? '');
            if ($id === '' || !ctype_digit($id)) continue;

            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = $r;
        }

        return $out;
    }

    protected function getLastLegacyId(NplCase $case): ?int
    {
        $last = CaseAction::query()
            ->where('npl_case_id', $case->id)
            ->where('source_system', 'legacy_sp')
            ->whereNotNull('source_ref_id')
            ->orderByRaw('CAST(source_ref_id AS UNSIGNED) DESC')
            ->value('source_ref_id');

        if ($last === null) return null;

        $n = (int) $last;
        return $n > 0 ? $n : null;
    }

    protected function normalizeSpType(string $raw): ?string
    {
        $s = strtolower(trim($raw));
        $s = str_replace([' ', '-', '_'], '', $s);

        return match (true) {
            $s === 'spak' || str_contains($s, 'spak') => 'spak',
            $s === 'sp1'  || str_contains($s, 'sp1')  || str_contains($s, 'peringatan1') => 'sp1',
            $s === 'sp2'  || str_contains($s, 'sp2')  || str_contains($s, 'peringatan2') => 'sp2',
            $s === 'sp3'  || str_contains($s, 'sp3')  || str_contains($s, 'peringatan3') => 'sp3',
            $s === 'spt'  || str_contains($s, 'spt')  || str_contains($s, 'terakhir')    => 'spt',
            $s === 'spjad'|| str_contains($s, 'spjad')|| str_contains($s, 'jaminan')     => 'spjad',
            default => null,
        };
    }
}
