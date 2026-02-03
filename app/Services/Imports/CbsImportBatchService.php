<?php

namespace App\Services\Imports;

use Illuminate\Support\Facades\DB;

class CbsImportBatchService
{
    public function start(string $module, ?string $source, ?string $filename): int
    {
        return (int) DB::table('import_batches')->insertGetId([
            'module' => $module,
            'source' => $source,
            'filename' => $filename,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function finish(int $batchId, int $total, int $ins, int $upd, int $skip, ?string $notes = null): void
    {
        DB::table('import_batches')->whereKey($batchId)->update([
            'rows_total' => $total,
            'rows_inserted' => $ins,
            'rows_updated' => $upd,
            'rows_skipped' => $skip,
            'notes' => $notes,
            'updated_at' => now(),
        ]);
    }
}
