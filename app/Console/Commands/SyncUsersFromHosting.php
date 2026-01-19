<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncUsersFromHosting extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:users-from-hosting';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync users dari database hosting ke database lokal (berdasarkan email)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Mulai sync users dari hosting...');

        try {
            // 1️⃣ Ambil data users dari DB hosting
            $rows = DB::connection('mysql_hosting')
                ->table('users')
                ->select(
                    'name',
                    'email',
                    'password',
                    'level',
                    'remember_token',
                    'created_at',
                    'updated_at'
                )
                ->whereNotNull('email')
                ->get();

            if ($rows->isEmpty()) {
                $this->warn('⚠️ Tidak ada data users yang diambil dari hosting.');
                return Command::SUCCESS;
            }

            // 2️⃣ Ubah ke array untuk upsert
            $payload = $rows->map(fn ($r) => (array) $r)->all();

            // 3️⃣ Pastikan kolom email unique di lokal
            // (jalankan sekali saja di DB, tidak wajib setiap command)
            // ALTER TABLE users ADD UNIQUE KEY users_email_unique (email);

            // 4️⃣ UPSERT ke DB lokal (email sebagai key)
            DB::table('users')->upsert(
                $payload,
                ['email'], // key utama
                [
                    'name',
                    'password',
                    'level',
                    'remember_token',
                    'updated_at',
                ]
            );

            $this->info('✅ Sync users selesai. Total user disinkronkan: ' . count($payload));
            Log::info('[SYNC USERS] Berhasil sync users dari hosting', [
                'total' => count($payload),
            ]);

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('❌ Sync users gagal: ' . $e->getMessage());

            Log::error('[SYNC USERS] Gagal sync users dari hosting', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
