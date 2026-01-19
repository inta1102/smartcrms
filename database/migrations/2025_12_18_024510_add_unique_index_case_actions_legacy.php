<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('case_actions', function (Blueprint $table) {

            // 1) Pastikan kolomnya ada
            if (!Schema::hasColumn('case_actions', 'source_system')) {
                $table->string('source_system', 30)->default('app')->after('npl_case_id');
            }

            // 2) Tambahkan index (tanpa cek index existence)
            // Catatan: ini aman kalau migrate:fresh.
            $table->index('source_system', 'case_actions_source_system_index');

            // Kalau kamu memang mau UNIQUE index legacy (sesuai nama migration),
            // contoh unique gabungan (sesuaikan dengan kolom legacy kamu):
            // $table->unique(['source_system','legacy_id'], 'case_actions_legacy_unique');
        });
    }

    public function down(): void
    {
        Schema::table('case_actions', function (Blueprint $table) {
            // Drop index kalau ada
            try {
                $table->dropIndex('case_actions_source_system_index');
            } catch (\Throwable $e) {
                // ignore
            }

            // Kolom boleh dibiarkan (recommended)
            // Kalau kamu yakin hanya untuk test dan belum dipakai, bisa drop:
            // if (Schema::hasColumn('case_actions', 'source_system')) {
            //     $table->dropColumn('source_system');
            // }
        });
    }
};
