<?php

namespace App\Services\Admin;

use App\Models\SystemHeartbeat;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class JobHealthService
{
    public function summary(): array
    {
        $pendingCount = DB::table('jobs')->whereNull('reserved_at')->count();
        $processingCount = DB::table('jobs')->whereNotNull('reserved_at')->count();
        $failedCount = DB::table('failed_jobs')->count();

        $oldestTs = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $oldestPendingMinutes = $oldestTs ? Carbon::createFromTimestamp((int)$oldestTs)->diffInMinutes(now()) : null;

        $pendingByQueue = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as total'))
            ->whereNull('reserved_at')
            ->groupBy('queue')
            ->orderByDesc('total')
            ->get();

        $topPendingJobs = $this->topPendingJobClasses();

        $topFailedExceptions = $this->topFailedExceptions();

        $runner = SystemHeartbeat::query()->where('component', 'queue_runner')->first();
        $runnerLastSeen = $runner?->beat_at;
        $runnerAgeMin = $runnerLastSeen ? $runnerLastSeen->diffInMinutes(now()) : null;

        // status thresholds (silakan sesuaikan)
        $runnerStatus = 'down';
        if ($runnerAgeMin === null) $runnerStatus = 'down';
        elseif ($runnerAgeMin <= 3) $runnerStatus = 'ok';
        elseif ($runnerAgeMin <= 10) $runnerStatus = 'warn';
        else $runnerStatus = 'down';

        return [
            'counts' => [
                'pending' => $pendingCount,
                'processing' => $processingCount,
                'failed' => $failedCount,
                'oldest_pending_minutes' => $oldestPendingMinutes,
            ],
            'runner' => [
                'status' => $runnerStatus,
                'last_seen' => $runnerLastSeen?->toDateTimeString(),
                'age_minutes' => $runnerAgeMin,
                'meta' => $runner?->meta,
            ],
            'pending_by_queue' => $pendingByQueue,
            'top_pending_jobs' => $topPendingJobs,
            'top_failed_exceptions' => $topFailedExceptions,
            'batches' => $this->batchSummary(),
        ];
    }

    protected function topPendingJobClasses(): array
    {
        // MySQL JSON extract. Jika displayName null, fallback commandName.
        $rows = DB::table('jobs')
            ->selectRaw("
                COALESCE(
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName')),
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.commandName')),
                  JSON_UNQUOTE(JSON_EXTRACT(payload, '$.job')),
                  'Unknown'
                ) as job_name,
                COUNT(*) as total
            ")
            ->whereNull('reserved_at')
            ->groupBy('job_name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        return $rows->map(fn($r) => ['job' => $r->job_name, 'total' => (int)$r->total])->all();
    }

    protected function topFailedExceptions(): array
    {
        // ambil 50 terakhir, lalu group di PHP (lebih aman daripada regex SQL)
        $rows = DB::table('failed_jobs')
            ->select(['id', 'exception'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $bucket = [];
        foreach ($rows as $r) {
            $sig = $this->exceptionSignature((string)$r->exception);
            $bucket[$sig] = ($bucket[$sig] ?? 0) + 1;
        }

        arsort($bucket);

        $out = [];
        foreach (array_slice($bucket, 0, 10, true) as $sig => $cnt) {
            $out[] = ['signature' => $sig, 'total' => $cnt];
        }
        return $out;
    }

    protected function exceptionSignature(string $ex): string
    {
        // ambil baris pertama (biasanya "Exception: message")
        $firstLine = trim(strtok($ex, "\n")) ?: 'Unknown exception';
        // rapikan panjang
        if (mb_strlen($firstLine) > 140) $firstLine = mb_substr($firstLine, 0, 140) . '…';
        return $firstLine;
    }

    protected function batchSummary(): array
    {
        // job_batches punya created_at/finished_at epoch int
        $exists = DB::getSchemaBuilder()->hasTable('job_batches');
        if (!$exists) return [];

        $rows = DB::table('job_batches')
            ->select(['id','name','total_jobs','pending_jobs','failed_jobs','created_at','finished_at','cancelled_at'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return $rows->map(function ($r) {
            $created = $r->created_at ? Carbon::createFromTimestamp((int)$r->created_at)->toDateTimeString() : null;
            $finished = $r->finished_at ? Carbon::createFromTimestamp((int)$r->finished_at)->toDateTimeString() : null;

            return [
                'id' => $r->id,
                'name' => $r->name,
                'total' => (int)$r->total_jobs,
                'pending' => (int)$r->pending_jobs,
                'failed' => (int)$r->failed_jobs,
                'created_at' => $created,
                'finished_at' => $finished,
                'cancelled_at' => $r->cancelled_at ? Carbon::createFromTimestamp((int)$r->cancelled_at)->toDateTimeString() : null,
            ];
        })->all();
    }
}
