<?php

namespace App\Services\Imports;

use App\Models\LoanDisbursement;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CbsDisbursementsCsvImporter
{
    public function __construct(
        protected CbsImportBatchService $batchSvc,
    ) {}

    /**
     * Header columns:
     * - account_no
     * - ao_code (optional)
     * - disb_date
     * - amount
     * - cif (optional)
     * - customer_name (optional)
     */
    public function import(string $csvPath, ?string $source = 'CBS'): array
    {
        $filename = basename($csvPath);
        $batchId = $this->batchSvc->start('disbursements', $source, $filename);

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

        $need = ['account_no','disb_date','amount'];
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

                $disbDate = $this->parseDate($row[$idx['disb_date']] ?? null);
                if (!$disbDate) { $skip++; continue; }

                $amount = $this->parseInt($row[$idx['amount']] ?? 0);
                if ($amount <= 0) { $skip++; continue; }

                $period = Carbon::parse($disbDate)->startOfMonth()->toDateString();

                $cif = isset($idx['cif']) ? trim((string)($row[$idx['cif']] ?? '')) : null;
                $customerName = isset($idx['customer_name']) ? trim((string)($row[$idx['customer_name']] ?? '')) : null;

                // unique(account_no, disb_date, amount)
                $existing = LoanDisbursement::query()
                    ->where('account_no', $accountNo)
                    ->whereDate('disb_date', $disbDate)
                    ->where('amount', $amount)
                    ->first();

                $payload = [
                    'account_no' => $accountNo,
                    'ao_code' => $aoCode ?: null,
                    'disb_date' => $disbDate,
                    'period' => $period,
                    'amount' => $amount,
                    'cif' => $cif ?: null,
                    'customer_name' => $customerName ?: null,
                    'source_file' => $filename,
                    'import_batch_id' => $batchId,
                ];

                if ($existing) {
                    $existing->fill($payload)->save();
                    $upd++;
                } else {
                    LoanDisbursement::query()->create($payload);
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

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                return Carbon::createFromFormat('Y-m-d', $s)->toDateString();
            }
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $s)) {
                return Carbon::createFromFormat('d/m/Y', $s)->toDateString();
            }
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
}
