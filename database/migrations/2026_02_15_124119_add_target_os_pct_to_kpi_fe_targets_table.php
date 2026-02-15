<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_fe_targets', function (Blueprint $table) {
            if (!Schema::hasColumn('kpi_fe_targets', 'target_os_turun_kol2_pct')) {
                $table->decimal('target_os_turun_kol2_pct', 8, 4)
                    ->nullable()
                    ->after('target_os_turun_kol2')
                    ->comment('Target Nett Penurunan OS Kol2 dalam persen (%)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpi_fe_targets', function (Blueprint $table) {
            if (Schema::hasColumn('kpi_fe_targets', 'target_os_turun_kol2_pct')) {
                $table->dropColumn('target_os_turun_kol2_pct');
            }
        });
    }
};
