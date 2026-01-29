<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueHeartbeat extends Command
{
    protected $signature = 'queue:heartbeat {--name=crms-worker} {--queue=crms,sync,default}';
    protected $description = 'Write queue runner heartbeat to database (queue_heartbeats)';

    public function handle(): int
    {
        $name  = (string) $this->option('name') ?: 'crms-worker';
        $queue = (string) $this->option('queue') ?: 'crms,sync,default';

        $t0 = microtime(true);

        // (Optional) sample counts ringan (biar dashboard bisa baca info tambahan)
        $pendingTotal = (int) DB::table('jobs')->whereNull('reserved_at')->count();

        $ms = (int) round((microtime(true) - $t0) * 1000);

        DB::table('queue_heartbeats')->updateOrInsert(
            ['name' => $name],
            [
                'last_seen_at' => now(),
                'last_run_processed' => 0,
                'last_run_failed' => 0,
                'last_run_ms' => $ms,
                'meta' => json_encode([
                    'queues' => $queue,
                    'host'   => gethostname(),
                    'pending_total' => $pendingTotal,
                ]),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->info("OK heartbeat name={$name} ms={$ms} pending_total={$pendingTotal}");
        return self::SUCCESS;
    }
}
