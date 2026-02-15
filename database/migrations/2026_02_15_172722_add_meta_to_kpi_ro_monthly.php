<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kpi_ro_monthly', function (Blueprint $table) {
            // === TopUp meta (CIF-based delta OS) ===
            $table->unsignedInteger('topup_cif_count')->default(0)->after('topup_score');
            $table->unsignedInteger('topup_cif_new_count')->default(0)->after('topup_cif_count');
            $table->decimal('topup_max_cif_amount', 18, 2)->default(0)->after('topup_cif_new_count');
            $table->decimal('topup_concentration_pct', 6, 2)->default(0)->after('topup_max_cif_amount');
            $table->text('topup_top3_json')->nullable()->after('topup_concentration_pct');

            // === Repayment transparency (basis RR) ===
            $table->decimal('repayment_total_os', 18, 2)->default(0)->after('repayment_score');
            $table->decimal('repayment_os_lancar', 18, 2)->default(0)->after('repayment_total_os');
        });

        // index ringan utk query period+ao
        Schema::table('kpi_ro_monthly', function (Blueprint $table) {
            $table->index(['period_month','calc_mode','ao_code'], 'kpi_ro_monthly_period_mode_ao_idx');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_ro_monthly', function (Blueprint $table) {
            $table->dropIndex('kpi_ro_monthly_period_mode_ao_idx');

            $table->dropColumn([
                'topup_cif_count',
                'topup_cif_new_count',
                'topup_max_cif_amount',
                'topup_concentration_pct',
                'topup_top3_json',
                'repayment_total_os',
                'repayment_os_lancar',
            ]);
        });
    }
};
