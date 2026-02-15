<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kpi_ao_monthlies', function (Blueprint $table) {
            // ✅ KPI AO versi baru (Realisasi Bulanan & Pertumbuhan NOA = Disbursement bulan itu)
            if (!Schema::hasColumn('kpi_ao_monthlies', 'os_disbursement')) {
                $table->bigInteger('os_disbursement')->default(0)->after('noa_growth');
            }
            if (!Schema::hasColumn('kpi_ao_monthlies', 'noa_disbursement')) {
                $table->integer('noa_disbursement')->default(0)->after('os_disbursement');
            }
            if (!Schema::hasColumn('kpi_ao_monthlies', 'os_disbursement_pct')) {
                $table->decimal('os_disbursement_pct', 7, 2)->default(0)->after('noa_disbursement');
            }
            if (!Schema::hasColumn('kpi_ao_monthlies', 'noa_disbursement_pct')) {
                $table->decimal('noa_disbursement_pct', 7, 2)->default(0)->after('os_disbursement_pct');
            }

            /**
             * ✅ Daily report kolom kamu sudah ada:
             * daily_report_target, daily_report_actual, daily_report_pct
             * Jadi kita TIDAK bikin daily_target/daily_actual/daily_pct (biar tidak dobel).
             * Nanti servicenya kita arahkan pakai daily_report_*.
             */

            // ✅ pastikan score_daily_report ada (karena error query kamu pakai score_daily)
            if (!Schema::hasColumn('kpi_ao_monthlies', 'score_daily_report')) {
                $table->decimal('score_daily_report', 6, 2)->default(0)->after('score_community');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpi_ao_monthlies', function (Blueprint $table) {
            $drop = [];
            foreach ([
                'os_disbursement','noa_disbursement','os_disbursement_pct','noa_disbursement_pct',
                'score_daily_report',
            ] as $col) {
                if (Schema::hasColumn('kpi_ao_monthlies', $col)) $drop[] = $col;
            }
            if (!empty($drop)) $table->dropColumn($drop);
        });
    }
};
