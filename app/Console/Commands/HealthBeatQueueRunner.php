<?php

namespace App\Console\Commands;

use App\Models\SystemHeartbeat;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class HealthBeatQueueRunner extends Command
{
    protected $signature = 'health:beat-queue-runner {--component=queue_runner}';
    protected $description = 'Update heartbeat for queue runner (cron) with basic queue metrics';

    public function handle(): int
    {
        $component = (string) $this->option('component');

        // queue metrics
        $pending = DB::table('jobs')->whereNull('reserved_at')->count();
        $processing = DB::table('jobs')->whereNotNull('reserved_at')->count();
        $failed = DB::table('failed_jobs')->count();

        $oldestTs = DB::table('jobs')
            ->whereNull('reserved_at')
            ->min('available_at');

        $oldestMin = $oldestTs
            ? Carbon::createFromTimestamp((int) $oldestTs)->diffInMinutes(now())
            : null;

        SystemHeartbeat::query()->updateOrCreate(
            ['component' => $component],
            [
                'status' => 'ok',
                'meta' => [
                    'pending' => $pending,
                    'processing' => $processing,
                    'failed' => $failed,
                    'oldest_pending_minutes' => $oldestMin,
                ],
                'beat_at' => now(),
            ]
        );

        $this->info("OK beat component={$component} pending={$pending} failed={$failed}");
        return self::SUCCESS;
    }
}
