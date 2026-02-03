<?php

namespace App\Console\Commands;

use App\Services\Imports\CbsInstallmentsCsvImporter;
use Illuminate\Console\Command;

class ImportInstallmentsCsv extends Command
{
    protected $signature = 'import:installments {path : Absolute/relative path to CSV} {--source=CBS}';
    protected $description = 'Import loan installments/payments CSV from CBS into loan_installments';

    public function handle(CbsInstallmentsCsvImporter $imp): int
    {
        $path = (string) $this->argument('path');
        $source = (string) $this->option('source');

        $res = $imp->import($path, $source);

        $this->info("OK installments import batch={$res['batch_id']} total={$res['total']} ins={$res['inserted']} upd={$res['updated']} skip={$res['skipped']}");
        return self::SUCCESS;
    }
}
