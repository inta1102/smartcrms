<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Models\JobLog;
use App\Jobs\SyncUsersFromSmartKpiApiJob;
use App\Services\Admin\JobHealthService;


class JobMonitorController extends Controller
{
    public function index(Request $request, JobHealthService $healthSvc)
    {
        $status   = $request->get('status', 'pending'); // pending|processing|all
        $queue    = $request->get('queue');
        $q        = trim((string) $request->get('q'));
        $olderMin = (int) $request->get('older', 0);

        $pendingCount = DB::table('jobs')->whereNull('reserved_at')->count();
        $processingCount = DB::table('jobs')->whereNotNull('reserved_at')->count();
        $failedCount = DB::table('failed_jobs')->count();

        $oldestPending = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
        $oldestPendingAge = $oldestPending
            ? Carbon::createFromTimestamp((int) $oldestPending)->diffForHumans()
            : null;

        $jobsQ = DB::table('jobs')
            ->select(['id', 'queue', 'attempts', 'available_at', 'reserved_at', 'created_at', 'payload'])
            ->orderByDesc('id');

        if ($status === 'pending') {
            $jobsQ->whereNull('reserved_at');
        } elseif ($status === 'processing') {
            $jobsQ->whereNotNull('reserved_at');
        }

        if ($queue) $jobsQ->where('queue', $queue);

        if ($olderMin > 0) {
            $cutoff = now()->subMinutes($olderMin)->timestamp;
            $jobsQ->where('available_at', '<=', $cutoff);
        }

        if ($q !== '') {
            $jobsQ->where('payload', 'like', '%' . $q . '%');
        }

        $jobs = $jobsQ->paginate(25)->withQueryString();

        $queues = DB::table('jobs')
            ->select('queue')->distinct()->orderBy('queue')->pluck('queue')->all();

        $jobs->getCollection()->transform(function ($row) {
            $payload = json_decode($row->payload, true);
            $row->job_name = $this->extractJobDisplayName($payload);

            $availableAt = $row->available_at ? Carbon::createFromTimestamp((int) $row->available_at) : null;
            $reservedAt  = $row->reserved_at ? Carbon::createFromTimestamp((int) $row->reserved_at) : null;

            $row->age = $availableAt ? $availableAt->diffForHumans() : '-';
            $row->available_at_h = $availableAt?->toDateTimeString();
            $row->reserved_at_h  = $reservedAt?->toDateTimeString();

            return $row;
        });

        $lastSyncSuccess = JobLog::where('job_key', 'sync_users_api')
            ->where('status', 'success')
            ->orderByDesc('ran_at')
            ->first();

        $lastSyncFailed = JobLog::where('job_key', 'sync_users_api')
            ->where('status', 'failed')
            ->orderByDesc('ran_at')
            ->first();

        $health = $healthSvc->summary();

        $hb = DB::table('queue_heartbeats')->where('name', 'queue_runner')->first();

        $ageMin = null;
        if ($hb?->last_seen_at) {
            $ageMin = now()->diffInMinutes(\Carbon\Carbon::parse($hb->last_seen_at));
        }

        $status = 'down';
        if (!is_null($ageMin)) {
            if ($ageMin <= 2) $status = 'ok';
            elseif ($ageMin <= 5) $status = 'warn';
            else $status = 'down';
        }

        $health['runner'] = [
            'status' => $status,
            'last_seen' => $hb?->last_seen_at ? \Carbon\Carbon::parse($hb->last_seen_at)->format('Y-m-d H:i:s') : '-',
            'age_minutes' => $ageMin,
            'last_run_processed' => $hb?->last_run_processed ?? 0,
            'last_run_failed' => $hb?->last_run_failed ?? 0,
            'last_run_ms' => $hb?->last_run_ms ?? 0,
        ];

        return view('admin.jobs.index', compact(
            'jobs','queues','status','queue','q','olderMin',
            'pendingCount','processingCount','failedCount','oldestPendingAge','lastSyncSuccess',
            'lastSyncFailed',
            'health'
        ));
    }

    public function failed(Request $request)
    {
        $queue = $request->get('queue');
        $q     = trim((string) $request->get('q'));

        $failedQ = DB::table('failed_jobs')
            ->select(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->orderByDesc('id');

        if ($queue) $failedQ->where('queue', $queue);
        if ($q !== '') $failedQ->where('payload', 'like', '%' . $q . '%');

        $failed = $failedQ->paginate(25)->withQueryString();

        $queues = DB::table('failed_jobs')
            ->select('queue')->distinct()->orderBy('queue')->pluck('queue')->all();

        $failed->getCollection()->transform(function ($row) {
            $payload = json_decode($row->payload, true);
            $row->job_name = $this->extractJobDisplayName($payload);
            $row->exception_short = mb_substr((string) $row->exception, 0, 250);
            return $row;
        });

        return view('admin.jobs.failed', compact('failed', 'queues', 'queue', 'q'));
    }

    public function retryFailed(int $id)
    {
        Artisan::call('queue:retry', ['id' => [$id]]);

        \Log::warning('JOB_MONITOR retry failed job', [
            'user_id' => auth()->id(),
            'failed_id' => $id,
        ]);

        return back()->with('status', "Failed job #$id di-retry.");
    }

    public function deleteFailed(int $id)
    {
        $deleted = DB::table('failed_jobs')->where('id', $id)->delete();

        \Log::warning('JOB_MONITOR delete failed job', [
            'user_id'   => auth()->id(),
            'failed_id' => $id,
            'deleted'   => $deleted,
        ]);

        if ($deleted) {
            return back()->with('status', "Failed job #$id dihapus dari failed_jobs.");
        }

        return back()->with('error', "Failed job #$id tidak ditemukan / gagal dihapus.");
    }


    protected function extractJobDisplayName(?array $payload): string
    {
        if (!$payload) return '-';
        if (!empty($payload['displayName'])) return (string) $payload['displayName'];
        if (!empty($payload['data']['commandName'])) return (string) $payload['data']['commandName'];
        if (!empty($payload['job'])) return (string) $payload['job'];
        return 'Job (unknown)';
    }

    public function runSyncUsers(Request $request)
    {
        // middleware sudah handle auth+kti_or_ti
        $full = (bool) $request->boolean('full', false);

        SyncUsersFromSmartKpiApiJob::dispatch($full, 500, 10)->onQueue('sync');

        return back()->with('status', '✅ SyncUsers job didispatch ke queue=sync.');
    }

}
