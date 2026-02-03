<?php

namespace App\Console\Commands;

use App\Services\Imports\CbsDisbursementsCsvImporter;
use Illuminate\Console\Command;

class ImportDisbursementsCsv extends Command
{
    protected $signature = 'import:disbursements {path : CSV path} {--source=CBS}';
    protected $description = 'Import loan disbursements CSV from CBS into loan_disbursements';

    public function handle(CbsDisbursementsCsvImporter $imp): int
    {
        $path = (string) $this->argument('path');
        $source = (string) $this->option('source');

        $res = $imp->import($path, $source);

        $this->info("OK disbursements import batch={$res['batch_id']} total={$res['total']} ins={$res['inserted']} upd={$res['updated']} skip={$res['skipped']}");
        return self::SUCCESS;
    }
}
