<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kpi_ro_monthly', function (Blueprint $table) {

            if (!Schema::hasColumn('kpi_ro_monthly', 'topup_realisasi_base')) {
                $table->decimal('topup_realisasi_base', 18, 2)->default(0)->after('topup_realisasi');
            }

            if (!Schema::hasColumn('kpi_ro_monthly', 'topup_adj_in')) {
                $table->decimal('topup_adj_in', 18, 2)->default(0)->after('topup_realisasi_base');
            }

            if (!Schema::hasColumn('kpi_ro_monthly', 'topup_adj_out')) {
                $table->decimal('topup_adj_out', 18, 2)->default(0)->after('topup_adj_in');
            }

            if (!Schema::hasColumn('kpi_ro_monthly', 'topup_adj_net')) {
                $table->decimal('topup_adj_net', 18, 2)->default(0)->after('topup_adj_out');
            }

            // Optional tapi sangat berguna untuk detail di Blade
            if (!Schema::hasColumn('kpi_ro_monthly', 'topup_adj_json')) {
                $table->longText('topup_adj_json')->nullable()->after('topup_top3_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpi_ro_monthly', function (Blueprint $table) {
            $cols = ['topup_realisasi_base','topup_adj_in','topup_adj_out','topup_adj_net','topup_adj_json'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('kpi_ro_monthly', $c)) $table->dropColumn($c);
            }
        });
    }
};