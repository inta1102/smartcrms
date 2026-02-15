<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_fe_monthlies', function (Blueprint $table) {

            if (!Schema::hasColumn('kpi_fe_monthlies', 'os_kol2_turun_pct')) {
                $table->decimal('os_kol2_turun_pct', 10, 4)
                    ->nullable()
                    ->after('os_kol2_turun_murni')
                    ->comment('Nett Penurunan OS Kol2 dalam persen terhadap OS awal');
            }

            if (!Schema::hasColumn('kpi_fe_monthlies', 'target_os_turun_kol2_pct')) {
                $table->decimal('target_os_turun_kol2_pct', 8, 4)
                    ->nullable()
                    ->after('target_os_turun_kol2')
                    ->comment('Target Nett Penurunan OS Kol2 dalam persen');
            }

        });
    }

    public function down(): void
    {
        Schema::table('kpi_fe_monthlies', function (Blueprint $table) {

            if (Schema::hasColumn('kpi_fe_monthlies', 'os_kol2_turun_pct')) {
                $table->dropColumn('os_kol2_turun_pct');
            }

            if (Schema::hasColumn('kpi_fe_monthlies', 'target_os_turun_kol2_pct')) {
                $table->dropColumn('target_os_turun_kol2_pct');
            }

        });
    }
};
