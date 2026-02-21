<?php

namespace App\Imports;

use App\Models\LoanInstallment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LoanInstallmentsImport implements ToCollection, WithHeadingRow, WithChunkReading
{
    public int $total = 0;
    public int $inserted = 0;
    public int $updated  = 0;
    public int $skipped  = 0;
    public int $upserted = 0;

    // ✅ tambahan: track error detail
    protected array $errors = [];
    protected int $maxErrorCapture = 200;

    protected string $positionDate;
    protected ?int $importBatchId;
    protected ?string $sourceFile;

    public function __construct(string $positionDate, ?int $importBatchId = null, ?string $sourceFile = null)
    {
        $this->positionDate  = Carbon::parse($positionDate)->format('Y-m-d');
        $this->importBatchId = $importBatchId;
        $this->sourceFile    = $sourceFile ?: 'installments_import';
    }

    // =========================================================
    // ✅ SUMMARY STANDARD (UI SAFE)
    // =========================================================
    public function summary(): array
    {
        return [
            'total'    => (int)$this->total,
            'inserted' => (int)$this->inserted,
            'updated'  => (int)$this->updated,
            'skipped'  => (int)$this->skipped,
            'upserted' => (int)$this->upserted,
        ];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function collection(Collection $rows)
    {
        if ($rows->isEmpty()) return;

        $this->total += $rows->count();
        $now = now();

        $payload = [];
        $rowNumberOffset = 0;

        foreach ($rows as $index => $row) {

            $rowNumber = $index + 2; // +2 karena heading row

            $r = is_array($row) ? $row : $row->toArray();

            $accountNo = $this->normalizeAccountNo($r['nofas'] ?? null);
            if (!$accountNo) {
                $this->skipWithReason($rowNumber, 'Account no (nofas) kosong / invalid');
                continue;
            }

            $angsKe = $this->parseIntOrNull($r['angske'] ?? null);
            if (!$angsKe || $angsKe <= 0) {
                $this->skipWithReason($rowNumber, 'Angsuran ke (angske) tidak valid');
                continue;
            }

            // =====================
            // DATE HANDLING
            // =====================
            $dueDate  = $this->parseDateOrNull($r['tglval'] ?? null)
                ?? $this->parseDateOrNull($r['tglbayar'] ?? null);

            $paidDate = $this->parseDateOrNull($r['tglbayar'] ?? null)
                ?? $this->parseDateOrNull($r['tglval'] ?? null);

            if (!$dueDate && $paidDate) $dueDate = $paidDate;
            if (!$paidDate && $dueDate) $paidDate = $dueDate;

            if (!$dueDate || !$paidDate) {
                $this->skipWithReason($rowNumber, 'Tanggal tidak valid (tglval/tglbayar)');
                continue;
            }

            $period = Carbon::parse($dueDate)->startOfMonth()->toDateString();

            // =====================
            // NOMINAL
            // =====================
            $principal = $this->parseMoneyInt($r['nlpokok'] ?? 0);
            $interest  = $this->parseMoneyInt($r['nlbunga'] ?? 0);
            $denda     = $this->parseMoneyInt($r['nldenda'] ?? 0);
            $penalty   = $this->parseMoneyInt($r['penalty'] ?? 0);
            $provisi   = $this->parseMoneyInt($r['provisi'] ?? 0);

            $penaltyPaid = $denda + $penalty;
            $feePaid     = $provisi;
            $paidAmount  = $principal + $interest + $penaltyPaid + $feePaid;

            if ($paidAmount <= 0) {
                $this->skipWithReason($rowNumber, 'Total paid_amount = 0');
                continue;
            }

            $aoCode = $this->normalizeCode($r['kdcollector'] ?? null, 6);
            $status = isset($r['status']) ? trim((string)$r['status']) : null;
            $notes  = isset($r['ket']) ? trim((string)$r['ket']) : null;

            $due  = Carbon::parse($dueDate)->startOfDay();
            $paid = Carbon::parse($paidDate)->startOfDay();

            $daysLate = $paid->greaterThan($due) ? (int)$due->diffInDays($paid) : 0;
            $isOntime = $paid->lessThanOrEqualTo($due);

            // =====================
            // FINGERPRINT (idempotent)
            // =====================
            $fpSource = implode('|', [
                $accountNo,
                $angsKe,
                $dueDate,
                $paidDate,
                $principal,
                $interest,
                $penaltyPaid,
                $feePaid,
                $status ?? '',
            ]);

            $fp = hash('sha256', $fpSource);

            $payload[] = [
                'trx_fingerprint' => $fp,
                'account_no' => $accountNo,
                'ao_code'    => $aoCode ?: null,
                'user_id'    => null,
                'period'     => $period,
                'due_date'   => $dueDate,
                'due_amount' => 0,
                'paid_date'  => $paidDate,
                'paid_amount'=> $paidAmount,
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

        // =====================
        // HITUNG INSERT / UPDATE
        // =====================
        $fps = collect($payload)->pluck('trx_fingerprint')->unique()->values();

        $existingCount = LoanInstallment::query()
            ->whereIn('trx_fingerprint', $fps->all())
            ->count();

        $chunkTotal = $fps->count();
        $chunkUpdated  = min($existingCount, $chunkTotal);
        $chunkInserted = max(0, $chunkTotal - $chunkUpdated);

        $this->updated  += $chunkUpdated;
        $this->inserted += $chunkInserted;
        $this->upserted += $chunkTotal;

        // =====================
        // UPSERT
        // =====================
        LoanInstallment::upsert(
            $payload,
            ['trx_fingerprint'],
            [
                'account_no','ao_code','period','due_date','due_amount',
                'paid_date','paid_amount',
                'angske','principal_paid','interest_paid','penalty_paid','fee_paid',
                'status','notes',
                'is_paid','is_paid_ontime','days_late',
                'source_file','import_batch_id','updated_at',
            ]
        );

        // Auto fix ao_code
        DB::statement("
            UPDATE loan_installments li
            JOIN loan_accounts la ON la.account_no = li.account_no
            SET li.ao_code = COALESCE(li.ao_code, la.ao_code, la.collector_code)
            WHERE li.ao_code IS NULL
        ");

        $this->mapUserIdByAoCode($payload, $now);
    }

    // =========================================================
    // USER MAPPING
    // =========================================================
    private function mapUserIdByAoCode(array $payload, $now): void
    {
        $aoCodes = collect($payload)
            ->pluck('ao_code')
            ->filter()
            ->unique()
            ->values();

        if ($aoCodes->isEmpty()) return;

        $scope = LoanInstallment::query()->whereNull('user_id');

        if ($this->importBatchId !== null) {
            $scope->where('import_batch_id', $this->importBatchId);
        }

        $users = User::whereIn('employee_code', $aoCodes)->pluck('id', 'employee_code');

        foreach ($users as $code => $uid) {
            (clone $scope)
                ->where('ao_code', $code)
                ->update([
                    'user_id'    => $uid,
                    'updated_at' => $now,
                ]);
        }
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    // =========================================================
    // HELPERS
    // =========================================================
    private function skipWithReason(int $row, string $reason): void
    {
        $this->skipped++;

        if (count($this->errors) < $this->maxErrorCapture) {
            $this->errors[] = [
                'row' => $row,
                'reason' => $reason,
            ];
        }
    }

    private function parseMoneyInt($v): int
    {
        if (!$v) return 0;
        $digits = preg_replace('/[^\d\-]/', '', (string)$v);
        return $digits ? (int)$digits : 0;
    }

    private function parseIntOrNull($v): ?int
    {
        if (!$v) return null;
        $digits = preg_replace('/[^\d]/', '', (string)$v);
        return $digits ? (int)$digits : null;
    }

    private function parseDateOrNull($raw): ?string
    {
        if (!$raw) return null;

        if (is_numeric($raw)) {
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
        if (!$value) return null;
        $v = trim((string)$value);
        if (ctype_digit($v)) return str_pad($v, $pad, '0', STR_PAD_LEFT);
        return $v;
    }

    private function normalizeAccountNo($value, int $length = 13): ?string
    {
        if (!$value) return null;
        $digits = preg_replace('/\D+/', '', (string)$value);
        return $digits ? str_pad($digits, $length, '0', STR_PAD_LEFT) : null;
    }

    
}