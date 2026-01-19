<?php

namespace App\Console\Commands;

use App\Jobs\SyncUsersFromSmartKpiApiJob;
use Illuminate\Console\Command;

class SyncUsersApi extends Command
{
    protected $signature = 'sync:users-api {--full} {--limit=500} {--max-loops=10}';

    protected $description = 'Dispatch job sync users dari hosting SmartKPI via API (queued)';

    public function handle()
    {
        $full = (bool) $this->option('full');
        $limit = (int) $this->option('limit');
        $maxLoops = (int) $this->option('max-loops');

        SyncUsersFromSmartKpiApiJob::dispatch($full, $limit, $maxLoops)->onQueue('sync');

        $this->info("✅ Dispatched SyncUsers API job ke queue=sync | full=" . ($full ? 'yes' : 'no') . " limit=$limit maxLoops=$maxLoops");
        return Command::SUCCESS;
    }
}
