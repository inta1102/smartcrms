<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_os_daily_aos', function (Blueprint $table) {

            // ===== NOA FE BUCKET =====
            $table->unsignedInteger('noa_kl')->default(0)->after('noa_dpk');
            $table->unsignedInteger('noa_d')->default(0)->after('noa_kl');
            $table->unsignedInteger('noa_m')->default(0)->after('noa_d');

            // ===== OS FE BUCKET =====
            $table->decimal('os_kl', 18, 2)->default(0)->after('os_dpk');
            $table->decimal('os_d', 18, 2)->default(0)->after('os_kl');
            $table->decimal('os_m', 18, 2)->default(0)->after('os_d');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_os_daily_aos', function (Blueprint $table) {

            $table->dropColumn([
                'noa_kl',
                'noa_d',
                'noa_m',
                'os_kl',
                'os_d',
                'os_m'
            ]);
        });
    }
};