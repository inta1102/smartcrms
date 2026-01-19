<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ✅ Kalau tabel users belum ada (di testing / env tertentu), skip
        if (!Schema::hasTable('users')) {
            return;
        }

        // ✅ Kalau kolom level belum ada, skip juga
        if (!Schema::hasColumn('users', 'level')) {
            return;
        }

        // MySQL: ubah kolom level jadi varchar
        DB::statement("ALTER TABLE `users` MODIFY `level` varchar(50) NULL DEFAULT 'STAFF'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'level')) {
            return;
        }

        // Kalau kamu punya tipe lama (ENUM), kembalikan di sini (opsional)
        // DB::statement("ALTER TABLE `users` MODIFY `level` enum('ADM','BO',...) NULL DEFAULT 'STAFF'");
    }
};
