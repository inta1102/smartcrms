<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('kpi_fe_monthlies', function (Blueprint $table) {
            $table->decimal('os_kol2_turun_total', 18, 2)->default(0)->after('os_kol2_turun');
            $table->decimal('os_kol2_turun_murni', 18, 2)->default(0)->after('os_kol2_turun_total');
            // optional tapi enak dibaca:
            $table->decimal('os_kol2_turun_migrasi', 18, 2)->default(0)->after('os_kol2_turun_murni');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kpi_fe_monthlies', function (Blueprint $table) {
            //
        });
    }
};
