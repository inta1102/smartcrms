<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class QueueRunOnce extends Command
{
    protected $signature = 'queue:run-once
        {--name=queue_runner : Heartbeat name}
        {--queue=sync,crms,default : Queue(s) comma separated}
        {--tries=3 : Max tries}
        {--max-jobs=50 : Max jobs diproses dalam sekali run}
        {--max-time=50 : Max detik dalam sekali run}';

    protected $description = 'Run queue worker (short-lived) and record heartbeat/metrics';

    public function handle(): int
    {
        $name    = (string) $this->option('name');
        $queues  = (string) $this->option('queue');
        $tries   = (int) $this->option('tries');
        $maxJobs = (int) $this->option('max-jobs');
        $maxTime = (int) $this->option('max-time');

        $t0 = microtime(true);

        // Snapshot sebelum run (untuk hitung processed/failed delta)
        $jobsBefore   = (int) DB::table('jobs')->count();
        $failedBefore = (int) DB::table('failed_jobs')->count();

        // Beat: start
        DB::table('queue_heartbeats')->updateOrInsert(
            ['name' => $name],
            [
                'last_seen_at' => now(),
                'last_run_processed' => 0,
                'last_run_failed' => 0,
                'last_run_ms' => 0,
                'meta' => json_encode([
                    'host' => gethostname(),
                    'php'  => PHP_VERSION,
                    'queues' => $queues,
                ]),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        // Jalankan worker pendek
        // Penting: --stop-when-empty biar gak nyangkut
        Artisan::call('queue:work', [
            '--queue' => $queues,
            '--tries' => $tries,
            '--max-jobs' => $maxJobs,
            '--max-time' => $maxTime,
            '--stop-when-empty' => true,
        ]);

        $ms = (int) round((microtime(true) - $t0) * 1000);

        $jobsAfter   = (int) DB::table('jobs')->count();
        $failedAfter = (int) DB::table('failed_jobs')->count();

        $processed = max(0, $jobsBefore - $jobsAfter);      // perkiraan job yang habis dari antrian
        $failedInc = max(0, $failedAfter - $failedBefore);  // failed bertambah

        // Beat: end + metrics
        DB::table('queue_heartbeats')->where('name', $name)->update([
            'last_seen_at' => now(),
            'last_run_processed' => $processed,
            'last_run_failed' => $failedInc,
            'last_run_ms' => $ms,
            'meta' => json_encode([
                'host' => gethostname(),
                'php'  => PHP_VERSION,
                'queues' => $queues,
                'jobs_before' => $jobsBefore,
                'jobs_after' => $jobsAfter,
                'failed_before' => $failedBefore,
                'failed_after' => $failedAfter,
            ]),
            'updated_at' => now(),
        ]);

        $this->info("OK run-once queues={$queues} processed={$processed} failed+={$failedInc} ms={$ms}");
        return self::SUCCESS;
    }
}
