<?php

namespace App\Imports;

use App\Models\LoanInstallment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LoanInstallmentsImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    public int $total = 0;
    public int $upserted = 0;
    public int $skipped = 0;

    protected string $positionDate;
    protected ?int $importBatchId;
    protected ?string $sourceFile;

    public function __construct(string $positionDate, ?int $importBatchId = null, ?string $sourceFile = null)
    {
        $this->positionDate  = Carbon::parse($positionDate)->format('Y-m-d');
        $this->importBatchId = $importBatchId;
        $this->sourceFile    = $sourceFile ?: 'installments_import';
    }

    public function summary(): array
    {
        return [
            'total'    => $this->total,
            'upserted' => $this->upserted,
            'skipped'  => $this->skipped,
        ];
    }

    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) return;

        $this->total += $rows->count();
        $now = now();

        // debug keys sekali per chunk (aman)
        static $logged = false;
        if (!$logged && $rows->isNotEmpty()) {
            $first = $rows->first();
            $r0 = is_array($first) ? $first : $first->toArray();
            Log::info('[IMPORT INST] first row keys', ['keys' => array_keys($r0)]);
            $logged = true;
        }

        $payload = [];

        foreach ($rows as $row) {
            $r = is_array($row) ? $row : $row->toArray();

            $accountNo = $this->normalizeAccountNo($r['nofas'] ?? null);
            if (!$accountNo) {
                $this->skipped++;
                continue;
            }

            // ✅ angsKe wajib (unique logika kamu)
            $angsKe = $this->parseIntOrNull($r['angske'] ?? null);
            if ($angsKe === null || $angsKe <= 0) {
                $this->skipped++;
                continue;
            }

            // =========================
            // ✅ Tanggal
            // due_date WAJIB NOT NULL (DB)
            // - due_date  : tglval (fallback tglbayar)
            // - paid_date : tglbayar (fallback tglval)
            // =========================
            $dueDate  = $this->parseDateOrNull($r['tglval'] ?? null)
                ?? $this->parseDateOrNull($r['tglbayar'] ?? null);

            $paidDate = $this->parseDateOrNull($r['tglbayar'] ?? null)
                ?? $this->parseDateOrNull($r['tglval'] ?? null);

            // fallback terakhir biar gak null
            if (!$dueDate && $paidDate) {
                $dueDate = $paidDate;
            }
            if (!$paidDate && $dueDate) {
                $paidDate = $dueDate;
            }
            if (!$dueDate || !$paidDate) {
                $this->skipped++;
                continue;
            }

            // period: pakai bulan due_date (lebih cocok untuk RR), fallback paid_date sudah aman
            $period = Carbon::parse($dueDate)->startOfMonth()->toDateString();

            // =========================
            // ✅ Nominal
            // =========================
            $principal = $this->parseMoneyInt($r['nlpokok'] ?? 0);
            $interest  = $this->parseMoneyInt($r['nlbunga'] ?? 0);
            $denda     = $this->parseMoneyInt($r['nldenda'] ?? 0);
            $penalty   = $this->parseMoneyInt($r['penalty'] ?? 0);
            $provisi   = $this->parseMoneyInt($r['provisi'] ?? 0);

            $penaltyPaid = $denda + $penalty;
            $feePaid     = $provisi;

            $paidAmount  = $principal + $interest + $penaltyPaid + $feePaid;

            // skip transaksi nol
            if ($paidAmount <= 0) {
                $this->skipped++;
                continue;
            }

            $aoCode = $this->normalizeCode($r['kdcollector'] ?? null, 6);
            $status = isset($r['status']) ? trim((string)$r['status']) : null;
            $notes  = isset($r['ket']) ? trim((string)$r['ket']) : null;

            // =========================
            // ✅ Flags RR (ontime & days_late)
            // =========================
            $due  = Carbon::parse($dueDate)->startOfDay();
            $paid = Carbon::parse($paidDate)->startOfDay();

            $daysLate = 0;
            if ($paid->greaterThan($due)) {
                $daysLate = (int) $due->diffInDays($paid);
            }

            $isOntime = $paid->lessThanOrEqualTo($due);

            // =========================
            // ✅ Fingerprint (idempotent)
            // Tambah angske + dueDate supaya aman dari bentrok
            // =========================
            $fpSource = implode('|', [
                $accountNo,
                (string)$angsKe,
                $dueDate,
                $paidDate,
                (string)$principal,
                (string)$interest,
                (string)$penaltyPaid,
                (string)$feePaid,
                (string)($status ?? ''),
            ]);
            $fp = hash('sha256', $fpSource);

            $payload[] = [
                'trx_fingerprint' => $fp,

                'account_no' => $accountNo,
                'ao_code'    => $aoCode ?: null,
                'user_id'    => null, // mapping setelah upsert

                'period'     => $period,

                // ✅ WAJIB (NOT NULL)
                'due_date'   => $dueDate,

                // due_amount dari file ini tidak ada (kita set 0 dulu)
                'due_amount' => 0,

                'paid_date'   => $paidDate,
                'paid_amount' => $paidAmount,

                // detail transaksi
                'angske'         => $angsKe,
                'principal_paid' => $principal,
                'interest_paid'  => $interest,
                'penalty_paid'   => $penaltyPaid,
                'fee_paid'       => $feePaid,

                'status' => $status,
                'notes'  => $notes,

                'is_paid'        => 1,
                'is_paid_ontime' => $isOntime ? 1 : 0,
                'days_late'      => $daysLate,

                'source_file'     => $this->sourceFile,
                'import_batch_id' => $this->importBatchId,

                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($payload)) return;

        // ✅ Upsert by fingerprint
        LoanInstallment::upsert(
            $payload,
            ['trx_fingerprint'],
            [
                'account_no','ao_code','period','due_date','due_amount',
                'paid_date','paid_amount',
                'angske','principal_paid','interest_paid','penalty_paid','fee_paid',
                'status','notes',
                'is_paid','is_paid_ontime','days_late',
                'source_file','import_batch_id',
                'updated_at',
            ]
        );

        // Auto-fix ao_code dari loan_accounts
        \DB::statement("
            UPDATE loan_installments li
            JOIN loan_accounts la ON la.account_no = li.account_no
            SET li.ao_code = COALESCE(li.ao_code, la.ao_code, la.collector_code)
            WHERE li.ao_code IS NULL
        ");

        // Auto-map user_id
        \DB::statement("
            UPDATE loan_installments li
            JOIN users u ON u.employee_code = li.ao_code
            SET li.user_id = u.id
            WHERE li.user_id IS NULL
            AND li.ao_code IS NOT NULL
        ");

        $this->upserted += count($payload);

        // ✅ mapping user_id by ao_code (dibatasi hanya untuk data hasil import ini)
        $this->mapUserIdByAoCode($payload, $now);
    }

    private function mapUserIdByAoCode(array $payload, $now): void
    {
        $aoCodes = collect($payload)
            ->pluck('ao_code')
            ->filter(fn($v) => $v !== null && trim((string)$v) !== '')
            ->map(fn($v) => trim((string)$v))
            ->unique()
            ->values();

        if ($aoCodes->isEmpty()) return;

        // scope update: hanya data import ini
        $scope = LoanInstallment::query()->whereNull('user_id');

        if ($this->importBatchId !== null) {
            $scope->where('import_batch_id', $this->importBatchId);
        } else {
            // fallback: source_file + period (bulan dari positionDate)
            $period = Carbon::parse($this->positionDate)->startOfMonth()->toDateString();
            $scope->where('source_file', (string)$this->sourceFile)
                  ->whereDate('period', $period);
        }

        // 1) match employee_code
        $userIdByEmp = User::query()
            ->whereIn('employee_code', $aoCodes->all())
            ->pluck('id', 'employee_code');

        foreach ($userIdByEmp as $code => $uid) {
            (clone $scope)
                ->where('ao_code', (string)$code)
                ->update([
                    'user_id'    => (int)$uid,
                    'updated_at' => $now,
                ]);
        }

        // 2) fallback match users.ao_code
        $userIdByAo = User::query()
            ->whereIn('ao_code', $aoCodes->all())
            ->pluck('id', 'ao_code');

        foreach ($userIdByAo as $code => $uid) {
            (clone $scope)
                ->where('ao_code', (string)$code)
                ->update([
                    'user_id'    => (int)$uid,
                    'updated_at' => $now,
                ]);
        }
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    // ===== Helpers =====

    private function parseMoneyInt($v): int
    {
        if ($v === null || $v === '') return 0;
        $digits = preg_replace('/[^\d\-]/', '', (string)$v);
        if ($digits === '' || $digits === '-') return 0;
        return (int)$digits;
    }

    private function parseIntOrNull($v): ?int
    {
        if ($v === null || $v === '') return null;
        $digits = preg_replace('/[^\d]/', '', (string)$v);
        if ($digits === '') return null;
        return (int)$digits;
    }

    private function parseDateOrNull($raw): ?string
    {
        if ($raw === null || $raw === '') return null;

        // excel numeric serial
        if (is_int($raw) || is_float($raw) || (is_numeric($raw) && (string)(int)$raw === (string)$raw)) {
            try {
                return ExcelDate::excelToDateTimeObject((float)$raw)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string)$raw)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeCode($value, int $pad = 6): ?string
    {
        if ($value === null) return null;
        $v = trim((string)$value);
        if ($v === '') return null;
        if (ctype_digit($v)) return str_pad($v, $pad, '0', STR_PAD_LEFT);
        return $v;
    }

    private function normalizeAccountNo($value, int $length = 13): ?string
    {
        if ($value === null) return null;
        $digits = preg_replace('/\D+/', '', (string)$value);
        if ($digits === '') return null;
        return str_pad($digits, $length, '0', STR_PAD_LEFT);
    }
}
