<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // tambah escalated & skipped
        DB::statement("
            ALTER TABLE action_schedules
            MODIFY status ENUM('pending','done','cancelled','escalated','skipped')
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // rollback ke 3 status awal
        DB::statement("
            ALTER TABLE action_schedules
            MODIFY status ENUM('pending','done','cancelled')
            NOT NULL DEFAULT 'pending'
        ");
    }
};
