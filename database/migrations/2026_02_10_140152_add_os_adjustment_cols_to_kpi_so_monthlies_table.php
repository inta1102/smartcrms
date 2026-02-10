<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_so_monthlies', function (Blueprint $table) {
            // ✅ Raw OS (sebelum adjustment) untuk transparansi
            if (!Schema::hasColumn('kpi_so_monthlies', 'os_disbursement_raw')) {
                $table->unsignedBigInteger('os_disbursement_raw')
                    ->default(0)
                    ->after('os_disbursement');
            }

            // ✅ Adjustment OS (titipan) yang mengurangi raw -> net
            if (!Schema::hasColumn('kpi_so_monthlies', 'os_adjustment')) {
                $table->unsignedBigInteger('os_adjustment')
                    ->default(0)
                    ->after('os_disbursement_raw');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpi_so_monthlies', function (Blueprint $table) {
            if (Schema::hasColumn('kpi_so_monthlies', 'os_adjustment')) {
                $table->dropColumn('os_adjustment');
            }

            if (Schema::hasColumn('kpi_so_monthlies', 'os_disbursement_raw')) {
                $table->dropColumn('os_disbursement_raw');
            }
        });
    }
};
