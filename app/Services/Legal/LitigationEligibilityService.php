<?php

namespace App\Services\Legal;

use App\Models\NplCase;
use Illuminate\Support\Carbon;

class LitigationEligibilityService
{
    /**
     * Konfigurasi rule: kamu bisa ubah tanpa bongkar logic.
     */
    public function requirements(): array
    {
        return [
            'persuasive' => [
                'label' => 'Bukti penanganan persuasif',
                'types' => ['call', 'visit', 'negotiation', 'wa', 'persuasive'],
                'min'   => 1,
            ],
            'sp' => [
                'label' => 'SP minimal terpenuhi',
                // minimal SP1+SP2 (sesuaikan dengan action_type legacy kamu)
                'must_have' => ['sp1', 'sp2'],
            ],
            'non_lit' => [
                'label' => 'Non-litigasi pernah dicoba',
                'types' => ['rs', 'ayda', 'non_lit', 'mediasi', 'somasi'],
                'min'   => 1,
            ],
            'target_expired' => [
                'label' => 'Target penyelesaian sudah lewat (opsional)',
                'enabled' => true, // kalau belum mau pakai, set false
            ],
        ];
    }

    /**
     * Return array checklist status + alasan, buat UI dan audit log.
     */
    public function evaluate(NplCase $case): array
    {
        $case->loadMissing(['actions', 'activeResolutionTarget']); // sesuaikan relasi

        $req = $this->requirements();

        $checks = [];

        // 1) Persuasif
        $checks['persuasive'] = $this->checkMinTypes(
            case: $case,
            types: $req['persuasive']['types'],
            min: $req['persuasive']['min']
        ) + ['label' => $req['persuasive']['label']];

        // 2) SP must have
        $checks['sp'] = $this->checkMustHaveTypes(
            case: $case,
            mustHave: $req['sp']['must_have']
        ) + ['label' => $req['sp']['label']];

        // 3) Non Lit
        $checks['non_lit'] = $this->checkMinTypes(
            case: $case,
            types: $req['non_lit']['types'],
            min: $req['non_lit']['min']
        ) + ['label' => $req['non_lit']['label']];

        // 4) Target expired (opsional)
        if (($req['target_expired']['enabled'] ?? false) === true) {
            $checks['target_expired'] = $this->checkTargetExpired($case) + ['label' => $req['target_expired']['label']];
        } else {
            $checks['target_expired'] = [
                'label'  => $req['target_expired']['label'],
                'ok'     => true,
                'reason' => 'Rule dimatikan.',
            ];
        }

        $ok = collect($checks)->every(fn ($c) => (bool)($c['ok'] ?? false));

        return [
            'ok'     => $ok,
            'checks' => $checks,
        ];
    }

    public function canStart(NplCase $case): bool
    {
        return (bool)($this->evaluate($case)['ok'] ?? false);
    }

    // -----------------------
    // Internal check helpers
    // -----------------------

    protected function checkMinTypes(NplCase $case, array $types, int $min): array
    {
        // asumsi log tindakan ada di relasi $case->actions (CaseAction / timeline)
        $count = $case->actions()
            ->whereIn('action_type', $types)
            ->count();

        return [
            'ok'     => $count >= $min,
            'reason' => $count >= $min
                ? "Ada {$count} catatan."
                : "Minimal {$min} catatan. Saat ini baru {$count}.",
            'meta'   => ['count' => $count, 'min' => $min, 'types' => $types],
        ];
    }

    protected function checkMustHaveTypes(NplCase $case, array $mustHave): array
    {
        $existing = $case->actions()
            ->whereIn('action_type', $mustHave)
            ->pluck('action_type')
            ->map(fn($v) => strtolower((string)$v))
            ->unique()
            ->values()
            ->all();

        $missing = array_values(array_diff(
            array_map('strtolower', $mustHave),
            $existing
        ));

        return [
            'ok'     => empty($missing),
            'reason' => empty($missing)
                ? 'Semua SP minimal terpenuhi.'
                : 'Kurang: ' . implode(', ', $missing),
            'meta'   => ['must_have' => $mustHave, 'missing' => $missing, 'existing' => $existing],
        ];
    }

    protected function checkTargetExpired(NplCase $case): array
    {
        $t = $case->activeResolutionTarget;

        if (!$t) {
            return [
                'ok' => false,
                'reason' => 'Belum ada target penyelesaian aktif.',
                'meta' => ['has_active_target' => false],
            ];
        }

        $targetDate = $t->target_date ? Carbon::parse($t->target_date)->startOfDay() : null;
        if (!$targetDate) {
            return [
                'ok' => false,
                'reason' => 'Target aktif tidak punya target_date.',
                'meta' => ['has_active_target' => true, 'target_date' => null],
            ];
        }

        $expired = $targetDate->lt(now()->startOfDay());

        return [
            'ok' => $expired,
            'reason' => $expired
                ? 'Target sudah lewat.'
                : 'Target belum lewat (belum boleh litigasi).',
            'meta' => ['target_date' => $targetDate->toDateString(), 'expired' => $expired],
        ];
    }
}
