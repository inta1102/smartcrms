<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class AoCasesExport implements FromCollection, WithHeadings, WithMapping
{
    protected Collection $rows;
    protected int $staleDays;

    public function __construct(Collection $rows, int $staleDays = 7)
    {
        $this->rows = $rows;
        $this->staleDays = $staleDays;
    }

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'AO Code',
            'AO Name',
            'Customer Name',
            'Account No',
            'CIF',
            'Kolek',
            'DPD',
            'Status',
            'Bucket ToDo',          // No Action / Stale / Overdue / Open / Closed
            'Opened At',
            'Last Action At',
            'Last Action',
            'Next Action',
            'Next Action Due',
        ];
    }

    public function map($case): array
    {
        $loan  = $case->loanAccount;
        $last  = $case->actions->sortByDesc('action_at')->first();
        $today = now();
        $staleLimit = $today->copy()->subDays($this->staleDays);

        $hasAction = $case->actions->isNotEmpty();

        // Status dasar
        if ($case->closed_at) {
            $status = 'Closed';
        } else {
            $status = 'Open';
        }

        // Bucket ToDo (harus sama logika dengan CASE di query)
        $bucket = 'Closed';
        if (is_null($case->closed_at)) {
            if (! $hasAction) {
                $bucket = 'No Action';
            } elseif ($last && $last->action_at < $staleLimit) {
                $bucket = 'Stale';
            } elseif ($last && $last->next_action_due && $last->next_action_due < $today) {
                $bucket = 'Overdue Next Action';
            } else {
                $bucket = 'Open';
            }
        }

        return [
            $loan->ao_code ?? '',
            $loan->ao_name ?? '',
            $loan->customer_name ?? '',
            $loan->account_no ?? '',
            $loan->cif ?? '',
            $loan->kolek ?? '',
            $loan->dpd ?? '',
            $status,
            $bucket,
            optional($case->opened_at)->format('Y-m-d'),
            optional($last?->action_at)->format('Y-m-d'),
            $last?->action ?? '',
            $last?->next_action ?? '',
            optional($last?->next_action_due)->format('Y-m-d'),
        ];
    }
}
