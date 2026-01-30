<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class EwsCkpnExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithColumnFormatting
{
    public function __construct(
        protected Collection $rows,
        protected string $posDate,
        protected int $minOs,
        protected int $dpdThreshold,
        protected string $reason,
        protected string $q
    ) {}

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Position Date',
            'CIF',
            'Customer Name',
            'Account No',
            'Product',
            'RS',
            'DPD',
            'OS Rekening',
            'OS CIF',
            'DPD Max (CIF)',
            'RS Any (CIF)',
            'Reason',
        ];
    }

    public function map($r): array
    {
        return [
            $r->position_date,
            $r->cif,
            $r->customer_name,
            $r->account_no,
            $r->product_type,
            (int) $r->is_restructured === 1 ? 'YES' : '-',
            (int) $r->dpd,
            (float) $r->outstanding,
            (float) $r->os_cif,
            (int) $r->dpd_max,
            (int) $r->rs_any,
            $r->reason,
        ];
    }

    public function columnFormats(): array
    {
        return [
            'H' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // OS Rek
            'I' => NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1, // OS CIF
        ];
    }
}
