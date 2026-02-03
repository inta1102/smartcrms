<?php

namespace App\Services\Imports;

use App\Models\LoanInstallment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CbsInstallmentsCsvImporter
{
    public function __construct(
        protected CbsImportBatchService $batchSvc,
    ) {}

    /**
     * Expect header columns (case-insensitive):
     * - account_no
     * - ao_code (optional)
     * - due_date (YYYY-MM-DD or DD/MM/YYYY)
     * - due_amount
     * - paid_date (optional)
     * - paid_amount (optional)
     */
    public function import(string $csvPath, ?string $source = 'CBS'): array
    {
        $filename = basename($csvPath);
        $batchId = $this->batchSvc->start('installments', $source, $filename);

        $fh = fopen($csvPath, 'rb');
        if (!$fh) {
            $this->batchSvc->finish($batchId, 0, 0, 0, 0, 'cannot open file');
            throw new \RuntimeException("Cannot open CSV: {$csvPath}");
        }

        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            $this->batchSvc->finish($batchId, 0, 0, 0, 0, 'empty header');
            return ['batch_id'=>$batchId,'total'=>0,'inserted'=>0,'updated'=>0,'skipped'=>0];
        }

        $cols = array_map(fn($h) => strtolower(trim((string)$h)), $header);
        $idx = array_flip($cols);

        $need = ['account_no','due_date','due_amount'];
        foreach ($need as $k) {
            if (!isset($idx[$k])) {
                fclose($fh);
                $this->batchSvc->finish($batchId, 0, 0, 0, 0, "missing column: {$k}");
                throw new \InvalidArgumentException("CSV missing column: {$k}");
            }
        }

        $total=0; $ins=0; $upd=0; $skip=0;

        DB::beginTransaction();
        try {
            while (($row = fgetcsv($fh)) !== false) {
                $total++;

                $accountNo = trim((string)($row[$idx['account_no']] ?? ''));
                if ($accountNo === '') { $skip++; continue; }

                $aoCode = isset($idx['ao_code']) ? trim((string)($row[$idx['ao_code']] ?? '')) : null;

                $dueDate = $this->parseDate($row[$idx['due_date']] ?? null);
                if (!$dueDate) { $skip++; continue; }

                $dueAmount = $this->parseInt($row[$idx['due_amount']] ?? 0);

                $paidDate = isset($idx['paid_date']) ? $this->parseDate($row[$idx['paid_date']] ?? null) : null;
                $paidAmount = isset($idx['paid_amount']) ? $this->parseInt($row[$idx['paid_amount']] ?? null) : null;

                $period = Carbon::parse($dueDate)->startOfMonth()->toDateString();

                [$isPaid, $isOntime, $daysLate] = $this->computePaymentFlags($dueDate, $dueAmount, $paidDate, $paidAmount);

                // upsert by unique(account_no, due_date)
                $existing = LoanInstallment::query()
                    ->where('account_no', $accountNo)
                    ->whereDate('due_date', $dueDate)
                    ->first();

                $payload = [
                    'account_no' => $accountNo,
                    'ao_code' => $aoCode ?: null,
                    'period' => $period,
                    'due_date' => $dueDate,
                    'due_amount' => $dueAmount,
                    'paid_date' => $paidDate,
                    'paid_amount' => $paidAmount,
                    'is_paid' => $isPaid,
                    'is_paid_ontime' => $isOntime,
                    'days_late' => $daysLate,
                    'source_file' => $filename,
                    'import_batch_id' => $batchId,
                ];

                if ($existing) {
                    $existing->fill($payload)->save();
                    $upd++;
                } else {
                    LoanInstallment::query()->create($payload);
                    $ins++;
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($fh);
            $this->batchSvc->finish($batchId, $total, $ins, $upd, $skip, 'failed: '.$e->getMessage());
            throw $e;
        }

        fclose($fh);
        $this->batchSvc->finish($batchId, $total, $ins, $upd, $skip);

        return ['batch_id'=>$batchId,'total'=>$total,'inserted'=>$ins,'updated'=>$upd,'skipped'=>$skip];
    }

    private function parseDate($v): ?string
    {
        $s = trim((string)$v);
        if ($s === '') return null;

        // try YYYY-MM-DD
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                return Carbon::createFromFormat('Y-m-d', $s)->toDateString();
            }
            // try DD/MM/YYYY
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s)) {
                return Carbon::createFromFormat('d/m/Y', $s)->toDateString();
            }
            // fallback parse
            return Carbon::parse($s)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseInt($v): int
    {
        if ($v === null) return 0;
        $s = trim((string)$v);
        if ($s === '') return 0;
        $s = str_replace([',',' '], '', $s);
        return (int) round((float) $s);
    }

    private function computePaymentFlags(string $dueDate, int $dueAmount, ?string $paidDate, ?int $paidAmount): array
    {
        if (!$paidDate || !$paidAmount || $paidAmount < $dueAmount) {
            return [false, false, 0];
        }

        $dDue = Carbon::parse($dueDate);
        $dPaid = Carbon::parse($paidDate);

        $daysLate = $dPaid->greaterThan($dDue) ? $dDue->diffInDays($dPaid) : 0;
        $isOntime = $dPaid->lte($dDue);

        return [true, $isOntime, $daysLate];
    }
}
