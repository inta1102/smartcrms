<?php

namespace App\Imports;

use App\Models\LoanAccountClosure;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;

class LoanAccountClosuresImport implements ToCollection, WithHeadingRow
{
    
    public string $sourceFile;
    public string $batchId;

    public int $inserted = 0;
    public int $updated  = 0;
    public int $skipped  = 0;

    /** @var array<int, array{row:int, reason:string, data:array}> */
    public array $errors = [];

    public function __construct(string $sourceFile = '')
    {
        $this->sourceFile = $sourceFile;
        $this->batchId = (string) Str::uuid();
    }

    public function headingRow(): int
    {
        return 1; // header ada di row 1
    }

    public function collection(Collection $rows)
    {
        $now = now();

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                // $i mulai dari 0, tapi row excel data mulai row 2
                $rowNum = $i + 2;

                $accountNo = $this->normalizeAccountNo($row['nofas'] ?? null, 13);

                if (!$accountNo) {
                    $this->skipped++;
                    $this->errors[] = ['row' => $rowNum, 'reason' => 'nofas kosong/invalid', 'data' => $row->toArray()];
                    continue;
                }
                if ($accountNo === '') {
                    $this->skipped++;
                    $this->errors[] = ['row' => $rowNum, 'reason' => 'nofas kosong', 'data' => $row->toArray()];
                    continue;
                }

                $closedDate = $this->parseExcelDate($row['tgllunas'] ?? null);
                if (!$closedDate) {
                    $this->skipped++;
                    $this->errors[] = ['row' => $rowNum, 'reason' => 'tgllunas invalid/kosong', 'data' => $row->toArray()];
                    continue;
                }

                $rawStatus = trim((string)($row['stskrd'] ?? ''));
                $closeType = $this->mapCloseType($rawStatus);

                $aoRaw = trim((string)($row['kdcollector'] ?? ''));
                $aoCode = $aoRaw === '' ? null : str_pad($aoRaw, 6, '0', STR_PAD_LEFT);

                $cif = trim((string)($row['cno'] ?? ''));
                $cif = $cif === '' ? null : $cif;

                $osPrev = $this->parseMoney($row['last_pokok'] ?? null);

                $payload = [
                    'cif'                => $cif,
                    'ao_code'            => $aoCode,
                    'closed_month'       => $closedDate->format('Y-m'),
                    'source_status_raw'  => $rawStatus !== '' ? strtoupper($rawStatus) : null,
                    'os_at_prev_snapshot'=> $osPrev,
                    'os_closed'          => $osPrev, // kalau CBS ada kolom OS saat close, bisa diisi
                    'source_file'        => $this->sourceFile ?: null,
                    'import_batch_id'    => $this->batchId,
                    'imported_at'        => $now,
                    'note'               => null,
                    'updated_at'         => $now,
                ];

                // unique key sesuai migration: account_no + closed_date + close_type
                $key = [
                    'account_no'  => $accountNo,
                    'closed_date' => $closedDate->toDateString(),
                    'close_type'  => $closeType,
                ];

                // cek existing dulu untuk hitung inserted/updated
                $exists = LoanAccountClosure::query()
                    ->where($key)
                    ->exists();

                LoanAccountClosure::query()->updateOrInsert($key, array_merge($payload, [
                    'created_at' => $exists ? DB::raw('created_at') : $now, // jaga created_at tetap
                ]));

                if ($exists) $this->updated++;
                else $this->inserted++;
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $this;
    }

    private function mapCloseType(string $raw): string
    {
        $v = strtoupper(trim($raw));

        // raw CBS mapping: LN/WO/AYDA
        if ($v === 'LN' || $v === 'LUNAS') return 'LUNAS';
        if ($v === 'WO' || $v === 'WRITE_OFF' || $v === 'WRITEOFF') return 'WRITE_OFF';
        if ($v === 'AYDA') return 'AYDA';

        return 'OTHER';
    }

    private function parseExcelDate($value): ?Carbon
    {
        if ($value === null || $value === '') return null;

        // Kalau numeric: bisa jadi Excel serial date
        if (is_numeric($value)) {
            // Maatwebsite biasanya sudah convert, tapi kita amankan:
            // Excel serial date start 1899-12-30
            try {
                return Carbon::createFromTimestampUTC(((int)$value - 25569) * 86400)->startOfDay();
            } catch (\Throwable $e) {
                return null;
            }
        }

        // Kalau string tanggal
        $s = trim((string)$value);
        if ($s === '') return null;

        // coba parse beberapa format umum CBS
        $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd.m.Y'];
        foreach ($formats as $fmt) {
            try {
                $dt = Carbon::createFromFormat($fmt, $s);
                if ($dt !== false) return $dt->startOfDay();
            } catch (\Throwable $e) {
                // continue
            }
        }

        // fallback Carbon parse
        try {
            return Carbon::parse($s)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseMoney($value): ?string
    {
        if ($value === null || $value === '') return null;

        // jika numeric, langsung format string decimal
        if (is_numeric($value)) {
            return number_format((float)$value, 2, '.', '');
        }

        // jika string "1.234.567,89" atau "1,234,567.89" dll
        $s = trim((string)$value);
        if ($s === '') return null;

        // buang Rp, spasi
        $s = str_replace(['Rp', 'rp', ' '], '', $s);

        // kalau format indo: 1.234.567,89 => remove thousand ".", replace decimal "," => "."
        // deteksi sederhana: jika ada "," dan "." dan koma ada di belakang => indo style
        if (str_contains($s, ',') && str_contains($s, '.')) {
            $lastComma = strrpos($s, ',');
            $lastDot   = strrpos($s, '.');
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                // US style: remove commas
                $s = str_replace(',', '', $s);
            }
        } elseif (str_contains($s, ',')) {
            // bisa jadi decimal comma
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } else {
            // only dot: assume decimal dot; remove thousand commas just in case
            $s = str_replace(',', '', $s);
        }

        // final numeric check
        if (!is_numeric($s)) return null;

        return number_format((float)$s, 2, '.', '');
    }

    private function normalizeAccountNo($value, int $length = 13): ?string
    {
        if ($value === null) return null;

        // ambil digit saja
        $digits = preg_replace('/\D+/', '', (string)$value);
        $digits = $digits !== null ? trim($digits) : '';

        if ($digits === '') return null;

        // standarkan jadi 13 digit (pad left zero)
        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }
}