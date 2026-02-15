<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_ro_monthly', function (Blueprint $table) {
            $table->unsignedInteger('dpk_migrasi_count')->default(0)->after('dpk_score');
            $table->decimal('dpk_migrasi_os', 18, 2)->default(0)->after('dpk_migrasi_count');

            // optional tapi sangat berguna utk transparansi denominator
            $table->decimal('dpk_total_os_akhir', 18, 2)->default(0)->after('dpk_migrasi_os');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_ro_monthly', function (Blueprint $table) {
            $table->dropColumn(['dpk_migrasi_count', 'dpk_migrasi_os', 'dpk_total_os_akhir']);
        });
    }
};
