<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ✅ existing scheduler legacy SP
Schedule::command('crms:sync-legacy-sp --limit=200 --only-open=1')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler-sync-legacy-sp.log'));

// ✅ scheduler sync users (incremental)
Schedule::command('sync:users-api --limit=500 --max-loops=20')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/scheduler-sync-users.log'));