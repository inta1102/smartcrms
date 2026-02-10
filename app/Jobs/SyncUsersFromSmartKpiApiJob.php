<?php

namespace App\Jobs;

use App\Models\SyncState;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\JobMonitor\JobLogService;


class SyncUsersFromSmartKpiApiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ✅ tampil di Job Monitor dengan queue khusus
    // public string $queue = 'sync';

    public int $tries = 5;

    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public int $timeout = 180;

    public function __construct(
        public bool $full = false,
        public int $limit = 500,
        public int $maxLoops = 10,
    ) {}

    public function handle(JobLogService $jobLog): void
    {
        $jobKey = 'sync_users_api';
        $started = microtime(true);

        $lock = Cache::lock('job:sync-users-api', 300);

        if (! $lock->get()) {
            Log::warning('SYNC_USERS_API skipped: lock exists');

            // log sebagai info (opsional)
            $jobLog->success($jobKey, 0, 0, [
                'skipped' => true,
                'reason'  => 'lock_exists',
            ], 'Skipped: lock exists');

            return;
        }

        $totalUpsert = 0;
        $lastSince = null;
        $loop = 0;

        try {
            $syncKey = 'users_sync_since';
            if ($this->full) {
                SyncState::putValue($syncKey, null);
            }

            $since    = SyncState::getValue($syncKey);
            $limit    = (int) $this->limit;
            $maxLoops = (int) $this->maxLoops;

            $lastSince = $since;

            Log::info('SYNC_USERS_API start', [
                'full' => $this->full,
                'since' => $since,
                'limit' => $limit,
                'maxLoops' => $maxLoops,
                'url' => config('services.user_sync.url'),
                'job_id' => optional($this->job)->getJobId(),
            ]);

            while ($loop < $maxLoops) {
                $loop++;

                $response = Http::withHeaders([
                        'X-USER-SYNC-KEY' => config('services.user_sync.key'),
                    ])
                    ->timeout(30)
                    ->retry(2, 500)
                    ->get(config('services.user_sync.url'), [
                        'since' => $since,
                        'limit' => $limit,
                    ]);

                if (! $response->successful()) {
                    throw new \RuntimeException("HTTP {$response->status()}");
                }

                $json = $response->json();

                if (empty($json['data'])) {
                    Log::info("SYNC_USERS_API no data. stop.", ['loop' => $loop]);
                    break;
                }

                $rows = $this->normalizeRows($json['data']);

                if (!empty($rows)) {
                    DB::table('users')->upsert(
                        $rows,
                        ['email'],
                        ['name', 'password', 'level', 'remember_token', 'updated_at']
                    );
                }

                $countRaw  = count($json['data']);
                $countRows = count($rows);
                $totalUpsert += $countRows;

                $nextSince = $json['meta']['next_since'] ?? null;

                Log::info("SYNC_USERS_API batch upsert", [
                    'loop' => $loop,
                    'count_raw' => $countRaw,
                    'count_rows' => $countRows,
                    'next_since' => $nextSince,
                ]);

                if ($nextSince) {
                    SyncState::putValue($syncKey, $nextSince);
                    $since = $nextSince;
                    $lastSince = $nextSince;
                }

                if ($countRaw < $limit) {
                    break;
                }
            }

            $durationMs = (int) ((microtime(true) - $started) * 1000);

            $jobLog->success($jobKey, $totalUpsert, $durationMs, [
                'full'      => $this->full,
                'limit'     => $this->limit,
                'maxLoops'  => $this->maxLoops,
                'loop'      => $loop,
                'last_since'=> $lastSince,
                'url'       => config('services.user_sync.url'),
                'queue'     => optional($this->job)?->getQueue(),
            ]);

            Log::info('SYNC_USERS_API done', [
                'loop' => $loop,
                'last_since' => $lastSince,
                'total_upsert_rows' => $totalUpsert,
                'duration_ms' => $durationMs,
            ]);

        } catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $started) * 1000);

            $jobLog->failed($jobKey, $e, [
                'full'      => $this->full,
                'limit'     => $this->limit,
                'maxLoops'  => $this->maxLoops,
                'loop'      => $loop,
                'last_since'=> $lastSince,
                'url'       => config('services.user_sync.url'),
            ], $totalUpsert, $durationMs);

            throw $e; // biar masuk failed_jobs juga
        } finally {
            optional($lock)->release();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SYNC_USERS_API failed', [
            'message' => $e->getMessage(),
            'job_id' => optional($this->job)->getJobId(),
        ]);
    }

    private function normalizeRows(array $data): array
    {
        $rows = [];

        foreach ($data as $u) {
            $email = trim((string)($u['email'] ?? ''));
            if ($email === '') continue;

            $levelRaw = (string)($u['level'] ?? '');
            $level = $this->mapLevel($levelRaw);

            $createdAt = $this->toDbDateTime($u['created_at'] ?? null);
            $updatedAt = $this->toDbDateTime($u['updated_at'] ?? null) ?? now()->format('Y-m-d H:i:s');

            $password = (string)($u['password'] ?? '');
            if ($password === '') {
                // fallback supaya tidak null
                $password = bcrypt(str()->random(24));
            }

            $rows[] = [
                'name'           => (string)($u['name'] ?? ''),
                'email'          => strtolower($email),
                'password'       => $password,
                'level'          => $level,
                'remember_token' => $u['remember_token'] ?? null,
                'created_at'     => $createdAt,
                'updated_at'     => $updatedAt,
            ];
        }

        return $rows;
    }

    private function toDbDateTime($value): ?string
    {
        if (empty($value)) return null;

        try {
            return Carbon::parse($value)
                ->setTimezone(config('app.timezone', 'Asia/Jakarta'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function mapLevel(string $level): string
    {
        $l = strtoupper(trim($level));
        if ($l === '') return 'STAFF';

        return match ($l) {
            'DIR', 'DIREKSI' => 'DIR',
            'TL'  => 'TL',
            'TLL' => 'TLL',
            'TLF' => 'TLF',
            'TLRO' => 'TLRO',
            'TLFE' => 'TLFE',
            'TLSO' => 'TLSO',
            'TLBE' => 'TLBE',
            'TLUM' => 'TLUM',

            'KSL' => 'KSL',
            'KBL' => 'KBL',
            'KSF' => 'KSF',
            'KBF' => 'KBF',
            'KSR' => 'KSR',
            'KBO' => 'KBO',
            'KSO' => 'KSO',
            'KSA' => 'KSA',
            'KSD' => 'KSD',
            'KTI' => 'KTI',

            'AO'  => 'AO',
            'SO'  => 'SO',
            'FO'  => 'FO',
            'FE'  => 'FE',
            'BE'  => 'BE',
            'TEL' => 'TEL',
            'TLR' => 'TLR',
            'CS'  => 'CS',
            'TI'  => 'TI',
            'BO'  => 'BO',
            'ACC' => 'ACC',
            'ADM' => 'ADM',
            'SDM' => 'SDM',
            'SSD' => 'SSD',
            'SAD' => 'SAD',
            'SPE' => 'SPE',
            'PE'  => 'PE',
            'SA'  => 'SA',
            'RO'  => 'RO',

            default => $l,
        };
    }
}
