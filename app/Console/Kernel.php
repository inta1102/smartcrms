<?php

namespace App\Console;

use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Console\Scheduling\Schedule;

class Kernel extends ConsoleKernel
{
    /**
     * Kalau kamu tidak perlu daftar manual (Laravel auto-discover),
     * kamu boleh kosongkan. Tapi aman kita biarkan yang sudah ada.
     */
    protected $commands = [
        \App\Console\Commands\RebuildSpSchedules::class,
        \App\Console\Commands\SyncLegacySp::class,

        // ✅ tambah ini kalau kamu mau explicit
        \App\Console\Commands\SyncUsersApi::class,
        \App\Console\Commands\ProcessSomasiDeadlines::class,
        \App\Console\Commands\RefreshWarningChain::class,
        \App\Console\Commands\KpiMarketingSnapshot::class,
        \App\Console\Commands\KpiMarketingCalculate::class,
        \App\Console\Commands\CalcMarketingKpiAchievements::class,
        \App\Console\Commands\HealthBeatQueueRunner::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        // =========================
        // 1) Scheduler existing kamu
        // =========================
        $schedule->command('crms:sync-legacy-sp --limit=200 --only-open=1')
            ->everyTenMinutes()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler-sync-legacy-sp.log'));

        // =========================
        // 2) ✅ Scheduler users sync (incremental)
        // =========================
        // Jalan tiap 5 menit, ambil batch 500, loop max 20 batch biar ngejar ketinggalan
        
        $schedule->command('sync:users-api --limit=500 --max-loops=20')
            ->everyFiveMinutes()
            ->withoutOverlapping(10)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/scheduler-sync-users.log'));

        $schedule->command('legal:send-reminders --limit=50')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('legal:process-somasi-deadlines')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->runInBackground(); // optional
        
        $schedule->command('legal:somasi:no-response')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        $schedule->command('wa:restruktur-due-h5')
            ->dailyAt('08:00')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('kpi:calc-marketing-achievements --period='.now()->format('Y-m').' --force')
            ->dailyAt('20:30')
            ->withoutOverlapping()
            ->runInBackground();

    }

    /**
     * ✅ penting: ini bikin Laravel tetap auto-load command di app/Console/Commands
     * dan juga routes/console.php
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
        require base_path('routes/console.php');
    }
}
