<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kpi_ao_monthlies', function (Blueprint $table) {
            // target/actual/pct untuk komunitas
            if (!Schema::hasColumn('kpi_ao_monthlies', 'community_target')) {
                $table->integer('community_target')->default(0)->after('activity_pct');
            }
            if (!Schema::hasColumn('kpi_ao_monthlies', 'community_actual')) {
                $table->integer('community_actual')->default(0)->after('community_target');
            }
            if (!Schema::hasColumn('kpi_ao_monthlies', 'community_pct')) {
                $table->decimal('community_pct', 5, 2)->default(0)->after('community_actual');
            }

            // target/actual/pct untuk daily report
            if (!Schema::hasColumn('kpi_ao_monthlies', 'daily_report_target')) {
                $table->integer('daily_report_target')->default(0)->after('community_pct');
            }
            if (!Schema::hasColumn('kpi_ao_monthlies', 'daily_report_actual')) {
                $table->integer('daily_report_actual')->default(0)->after('daily_report_target');
            }
            if (!Schema::hasColumn('kpi_ao_monthlies', 'daily_report_pct')) {
                $table->decimal('daily_report_pct', 5, 2)->default(0)->after('daily_report_actual');
            }

            // skor tambahan
            if (!Schema::hasColumn('kpi_ao_monthlies', 'score_community')) {
                $table->decimal('score_community', 6, 2)->default(0)->after('score_activity');
            }
            if (!Schema::hasColumn('kpi_ao_monthlies', 'score_daily_report')) {
                $table->decimal('score_daily_report', 6, 2)->default(0)->after('score_community');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpi_ao_monthlies', function (Blueprint $table) {
            $drops = [
                'score_daily_report',
                'score_community',
                'daily_report_pct',
                'daily_report_actual',
                'daily_report_target',
                'community_pct',
                'community_actual',
                'community_target',
            ];

            foreach ($drops as $col) {
                if (Schema::hasColumn('kpi_ao_monthlies', $col)) $table->dropColumn($col);
            }
        });
    }
};
