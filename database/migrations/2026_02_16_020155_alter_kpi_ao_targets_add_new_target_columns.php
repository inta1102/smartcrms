<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kpi_ao_targets', function (Blueprint $table) {
            if (!Schema::hasColumn('kpi_ao_targets', 'target_os_disbursement')) {
                $table->bigInteger('target_os_disbursement')->default(0)->after('ao_code');
            }
            if (!Schema::hasColumn('kpi_ao_targets', 'target_noa_disbursement')) {
                $table->integer('target_noa_disbursement')->default(0)->after('target_os_disbursement');
            }
            if (!Schema::hasColumn('kpi_ao_targets', 'target_community')) {
                $table->integer('target_community')->default(0)->after('target_rr');
            }
            if (!Schema::hasColumn('kpi_ao_targets', 'target_daily_report')) {
                $table->integer('target_daily_report')->default(0)->after('target_community');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpi_ao_targets', function (Blueprint $table) {
            $drop = [];
            foreach (['target_os_disbursement','target_noa_disbursement','target_community','target_daily_report'] as $col) {
                if (Schema::hasColumn('kpi_ao_targets', $col)) $drop[] = $col;
            }
            if (!empty($drop)) $table->dropColumn($drop);
        });
    }
};
