<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_os_daily_aos', function (Blueprint $table) {
            // ✅ tambah kolom DPK harian
            if (!Schema::hasColumn('kpi_os_daily_aos', 'os_dpk')) {
                $table->decimal('os_dpk', 18, 2)->default(0)->after('os_lt');
            }
            if (!Schema::hasColumn('kpi_os_daily_aos', 'noa_dpk')) {
                $table->unsignedInteger('noa_dpk')->default(0)->after('noa_lt');
            }
        });

        // ✅ pastikan unique key untuk upsert (position_date, ao_code)
        // (kalau sudah ada, biarkan)
        try {
            Schema::table('kpi_os_daily_aos', function (Blueprint $table) {
                $table->unique(['position_date', 'ao_code'], 'kpi_os_daily_aos_pos_ao_unique');
            });
        } catch (\Throwable $e) {
            // ignore: index kemungkinan sudah ada / nama beda
        }
    }

    public function down(): void
    {
        // drop unique kalau sempat dibuat oleh migration ini
        try {
            Schema::table('kpi_os_daily_aos', function (Blueprint $table) {
                $table->dropUnique('kpi_os_daily_aos_pos_ao_unique');
            });
        } catch (\Throwable $e) {
            // ignore
        }

        Schema::table('kpi_os_daily_aos', function (Blueprint $table) {
            if (Schema::hasColumn('kpi_os_daily_aos', 'os_dpk')) {
                $table->dropColumn('os_dpk');
            }
            if (Schema::hasColumn('kpi_os_daily_aos', 'noa_dpk')) {
                $table->dropColumn('noa_dpk');
            }
        });
    }
};
