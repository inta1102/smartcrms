<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kpi_ao_targets', function (Blueprint $table) {
            if (!Schema::hasColumn('kpi_ao_targets', 'target_community')) {
                $table->integer('target_community')->default(0)->after('target_activity');
            }
            if (!Schema::hasColumn('kpi_ao_targets', 'target_daily_report')) {
                $table->integer('target_daily_report')->default(0)->after('target_community');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpi_ao_targets', function (Blueprint $table) {
            if (Schema::hasColumn('kpi_ao_targets', 'target_daily_report')) {
                $table->dropColumn('target_daily_report');
            }
            if (Schema::hasColumn('kpi_ao_targets', 'target_community')) {
                $table->dropColumn('target_community');
            }
        });
    }
};
