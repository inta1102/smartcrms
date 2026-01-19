<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncUsersFromHostingApi extends Command
{
    // protected $signature = 'sync:users-api {--full : Abaikan since, ambil dari awal}';
    protected $signature = 'sync:users-hosting-api {--full} {--limit=500} {--max-loops=10}';

    protected $description = 'LEGACY: Sync users dari Hosting API (fallback) — jangan dipakai jika sync:users-api sudah stabil';


    public function handle(): int
    {
        $url   = (string) env('USER_SYNC_URL');
        $key   = (string) env('USER_SYNC_API_KEY');
        $limit = (int) (env('USER_SYNC_LIMIT', 500));

        if ($url === '' || $key === '') {
            $this->error('USER_SYNC_URL / USER_SYNC_API_KEY belum diset di .env');
            return Command::FAILURE;
        }

        $stateKey = 'users_sync_since';

        $since = null;
        if (!$this->option('full')) {
            $since = DB::table('sync_states')->where('key', $stateKey)->value('value');
            $since = $since ?: null;
        }

        $this->info('🔄 Sync users via API...');
        $this->line('URL   : ' . $url);
        $this->line('Since : ' . ($since ?: '(null)'));
        $this->line('Limit : ' . $limit);

        $totalUpsert = 0;
        $nextSince = $since;

        try {
            while (true) {
                $resp = Http::timeout(30)
                    ->retry(3, 500)
                    ->withHeaders(['X-USER-SYNC-KEY' => $key])
                    ->get($url, [
                        'since' => $nextSince,
                        'limit' => $limit,
                    ]);

                if (!$resp->ok()) {
                    $this->error('❌ HTTP error: ' . $resp->status() . ' ' . $resp->body());
                    return Command::FAILURE;
                }

                $json = $resp->json();
                $data = $json['data'] ?? [];
                $meta = $json['meta'] ?? [];

                $count = (int) ($meta['count'] ?? count($data));
                $newNextSince = $meta['next_since'] ?? $nextSince;

                if ($count <= 0 || empty($data)) {
                    $this->info('✅ Tidak ada perubahan users.');
                    break;
                }

                // Pastikan email ada
                $payload = collect($data)
                    ->filter(fn ($u) => !empty($u['email']))
                    ->map(function ($u) {
                        return [
                            'name' => $u['name'] ?? null,
                            'email' => $u['email'],
                            'password' => $u['password'] ?? null,
                            'level' => $u['level'] ?? null,
                            'remember_token' => $u['remember_token'] ?? null,
                            'created_at' => $u['created_at'] ?? now(),
                            'updated_at' => $u['updated_at'] ?? now(),
                        ];
                    })
                    ->values()
                    ->all();

                // UPSERT by email
                DB::table('users')->upsert(
                    $payload,
                    ['email'],
                    ['name', 'password', 'level', 'remember_token', 'updated_at']
                );

                $totalUpsert += count($payload);
                $this->info("⬆️ Upsert batch: {$count} | next_since={$newNextSince}");

                // Update nextSince untuk iterasi berikutnya
                if ($newNextSince === $nextSince) {
                    // safety break supaya gak loop
                    break;
                }
                $nextSince = $newNextSince;

                // Jika batch < limit, berarti sudah habis
                if ($count < $limit) {
                    break;
                }
            }

            // Simpan state terakhir
            DB::table('sync_states')->updateOrInsert(
                ['key' => $stateKey],
                ['value' => $nextSince, 'updated_at' => now(), 'created_at' => now()]
            );

            $this->info("✅ Sync selesai. Total upsert: {$totalUpsert}. Last since: " . ($nextSince ?: '(null)'));

            Log::info('[SYNC USERS API] success', [
                'total' => $totalUpsert,
                'since' => $since,
                'last_since' => $nextSince,
            ]);

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('❌ Sync gagal: ' . $e->getMessage());
            Log::error('[SYNC USERS API] failed', [
                'error' => $e->getMessage(),
            ]);
            return Command::FAILURE;
        }
    }
}
