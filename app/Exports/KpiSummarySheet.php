<?php

namespace App\Exports;

use App\Services\Kpi\KpiSummaryService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class KpiSummarySheet implements FromArray, WithHeadings, WithTitle
{
    public function __construct(
        public string $rawPeriod,
        public array $filters = []
    ) {}

    public function title(): string
    {
        return 'SUMMARY';
    }

    public function headings(): array
    {
        return [
            'level',
            'role',
            'name',
            'unit',
            'scope_count',
            'period',
            'mode',
            'score',
            'ach',
            'rank',
            'ref_user_id',
            'ref_ao_code',
        ];
    }

    public function array(): array
    {
        /** @var KpiSummaryService $svc */
        $svc = app(KpiSummaryService::class);

        // sama persis seperti controller index
        $rows = $svc->build($this->rawPeriod, $this->filters);

        $out = [];
        foreach (($rows ?? []) as $r) {
            $out[] = [
                $r['level'] ?? null,
                $r['role'] ?? null,
                $r['name'] ?? null,
                $r['unit'] ?? null,
                $r['scope_count'] ?? null,
                $r['period'] ?? null,
                $r['mode'] ?? null,
                (float)($r['score'] ?? 0),
                (float)($r['ach'] ?? 0),
                $r['rank'] ?? null,
                (int)($r['ref']['user_id'] ?? 0),
                (string)($r['ref']['ao_code'] ?? ''),
            ];
        }

        return $out;
    }
}