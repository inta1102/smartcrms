<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class KpiAllExport implements WithMultipleSheets
{
    public function __construct(
        public string $rawPeriod,
        public array $filters = []
    ) {}

    public function sheets(): array
    {
        return [
            new KpiSummarySheet($this->rawPeriod, $this->filters),
            new KpiComponentsSheet($this->rawPeriod, $this->filters),
        ];
    }
}