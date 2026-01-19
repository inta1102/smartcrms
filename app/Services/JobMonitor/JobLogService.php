<?php

namespace App\Services\JobMonitor;

use App\Models\JobLog;
use Illuminate\Support\Facades\Log;

class JobLogService
{
    public function success(string $jobKey, ?int $count, ?int $durationMs, array $meta = [], ?string $message = null): void
    {
        try {
            JobLog::create([
                'job_key'     => $jobKey,
                'status'      => 'success',
                'count'       => $count,
                'duration_ms' => $durationMs,
                'message'     => $message,
                'meta'        => $meta,
                'ran_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('JOBLOG success write failed', [
                'job_key' => $jobKey,
                'err' => $e->getMessage(),
            ]);
        }
    }

    public function failed(string $jobKey, \Throwable $e, array $meta = [], ?int $count = null, ?int $durationMs = null): void
    {
        try {
            JobLog::create([
                'job_key'     => $jobKey,
                'status'      => 'failed',
                'count'       => $count,
                'duration_ms' => $durationMs,
                'message'     => mb_substr($e->getMessage(), 0, 800),
                'meta'        => $meta,
                'ran_at'      => now(),
            ]);
        } catch (\Throwable $ex) {
            Log::error('JOBLOG failed write failed', [
                'job_key' => $jobKey,
                'err' => $ex->getMessage(),
                'orig_err' => $e->getMessage(),
            ]);
        }
    }
}
