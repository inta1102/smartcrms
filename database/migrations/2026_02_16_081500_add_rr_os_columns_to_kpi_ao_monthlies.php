<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kpi_ao_monthlies', function (Blueprint $table) {
            if (!Schema::hasColumn('kpi_ao_monthlies', 'rr_os_total')) {
                $table->bigInteger('rr_os_total')->default(0)->after('rr_pct');
            }
            if (!Schema::hasColumn('kpi_ao_monthlies', 'rr_os_current')) {
                $table->bigInteger('rr_os_current')->default(0)->after('rr_os_total');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpi_ao_monthlies', function (Blueprint $table) {
            if (Schema::hasColumn('kpi_ao_monthlies', 'rr_os_current')) {
                $table->dropColumn('rr_os_current');
            }
            if (Schema::hasColumn('kpi_ao_monthlies', 'rr_os_total')) {
                $table->dropColumn('rr_os_total');
            }
        });
    }
};
