<?php

namespace App\Exports;

use App\Models\LoanAccount;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class EwsDetailExport implements FromCollection, WithHeadings, WithMapping, WithColumnFormatting, ShouldAutoSize
{
    public function __construct(
        public string $scope = 'AG6',
        public string $agunan = 'ALL',
        public int $limit = 300,
        public ?string $positionDate = null,
    ) {}

    public function collection(): Collection
    {
        $q = LoanAccount::query()
            ->select([
                'account_no',
                'customer_name',
                'ao_code',
                'collector_code',
                'jenis_agunan',
                'keterangan_sandi',
                'tgl_kolek',
                'outstanding',
                'cadangan_ppap',
                'nilai_agunan_yg_diperhitungkan',
                'position_date',
            ])
            // usia macet (bulan)
            ->selectRaw("
                CASE
                    WHEN tgl_kolek IS NULL THEN NULL
                    ELSE TIMESTAMPDIFF(
                        MONTH,
                        DATE(tgl_kolek),
                        DATE(COALESCE(?, CURDATE()))
                    )
                END AS usia_macet_bulan
            ", [$this->positionDate ?: null]);

        // =========================
        // filter posisi tanggal
        // =========================
        if (!empty($this->positionDate)) {
            $q->whereDate('position_date', $this->positionDate);
        }

        // =========================
        // tentukan final agunan (6/9/ALL)
        // =========================
        $agunanStr = is_null($this->agunan) ? '' : trim((string)$this->agunan);
        $agunanUp  = strtoupper($agunanStr);

        $finalAgunan = null; // null = ALL

        // kalau user pilih agunan 6/9
        if ($agunanUp !== '' && $agunanUp !== 'ALL') {
            $digits = preg_replace('/[^\d]/', '', $agunanStr);
            $num = $digits === '' ? null : (int)$digits;
            if (in_array($num, [6, 9], true)) {
                $finalAgunan = $num;
            }
        }

        // kalau masih ALL -> derive dari scope ag6/ag9
        if (is_null($finalAgunan)) {
            $scopeUp = strtoupper(trim((string)$this->scope));
            if (in_array($scopeUp, ['AG6','AG9'], true)) {
                $finalAgunan = (int) str_replace('AG', '', $scopeUp); // 6/9
            }
        }

        // =========================
        // ✅ RULE WINDOW USIA (SAMA DENGAN VIEW)
        // =========================
        // - Agunan 6: usia 20-24
        // - Agunan 9: usia 9-12
        // - ALL: gabungan keduanya
        $q->whereNotNull('tgl_kolek');

        $q->where(function ($w) use ($finalAgunan) {

            // Kalau filter spesifik 6
            if ($finalAgunan === 6) {
                $w->where('jenis_agunan', 6)
                ->whereRaw("TIMESTAMPDIFF(MONTH, DATE(tgl_kolek), DATE(COALESCE(?, CURDATE()))) BETWEEN 20 AND 24", [
                    $this->positionDate ?: null
                ]);
                return;
            }

            // Kalau filter spesifik 9
            if ($finalAgunan === 9) {
                $w->where('jenis_agunan', 9)
                ->whereRaw("TIMESTAMPDIFF(MONTH, DATE(tgl_kolek), DATE(COALESCE(?, CURDATE()))) BETWEEN 9 AND 12", [
                    $this->positionDate ?: null
                ]);
                return;
            }

            // ALL: gabungkan dua kondisi
            $w->where(function ($x) {
                    $x->where('jenis_agunan', 6)
                    ->whereRaw("TIMESTAMPDIFF(MONTH, DATE(tgl_kolek), DATE(COALESCE(?, CURDATE()))) BETWEEN 20 AND 24", [
                        $this->positionDate ?: null
                    ]);
                })
            ->orWhere(function ($x) {
                    $x->where('jenis_agunan', 9)
                    ->whereRaw("TIMESTAMPDIFF(MONTH, DATE(tgl_kolek), DATE(COALESCE(?, CURDATE()))) BETWEEN 9 AND 12", [
                        $this->positionDate ?: null
                    ]);
                });
        });

        // =========================
        // ordering seperti UI: usia desc lalu OS desc
        // =========================
        $q->orderByRaw('CASE WHEN usia_macet_bulan IS NULL THEN 1 ELSE 0 END ASC')
        ->orderBy('usia_macet_bulan', 'desc')
        ->orderBy('outstanding', 'desc');

        return $q->limit($this->limit)->get();
    }

    public function headings(): array
    {
        return [
            'No Rek',
            'Nama',
            'AO',
            'Agunan',
            'Tgl Kolek',
            'Usia (bln)',
            'OS',
            'PPKA',
            'Pengurang',
        ];
    }

    public function map($row): array
    {
        $agunanLabel = match((int)($row->jenis_agunan ?? 0)) {
            6 => '6 - TANAH/BANGUNAN/RUMAH',
            9 => '9 - LAINNYA',
            default => (string)($row->jenis_agunan ?? ''),
        };

        $tgl = $row->tgl_kolek ? \Carbon\Carbon::parse($row->tgl_kolek)->format('Y-m-d') : '';

        return [
            (string) $row->account_no,
            (string) $row->customer_name,
            (string) ($row->ao_code ?: $row->collector_code),
            $agunanLabel,
            $tgl,
            is_null($row->usia_macet_bulan) ? '' : (int)$row->usia_macet_bulan,
            (float) ($row->outstanding ?? 0),
            (float) ($row->cadangan_ppap ?? 0),
            (float) ($row->nilai_agunan_yg_diperhitungkan ?? 0),
        ];
    }

    public function columnFormats(): array
    {
        return [
            'F' => NumberFormat::FORMAT_NUMBER,               // usia bln
            'G' => '#,##0',                                   // OS
            'H' => '#,##0',                                   // PPKA
            'I' => '#,##0',                                   // Pengurang
        ];
    }
}
