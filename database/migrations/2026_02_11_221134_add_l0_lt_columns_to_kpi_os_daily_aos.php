<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kpi_os_daily_aos', function (Blueprint $table) {
            // OS + NOA untuk L0 dan LT
            $table->decimal('os_l0', 18, 2)->default(0)->after('os_total');
            $table->unsignedInteger('noa_l0')->default(0)->after('noa_total');

            $table->decimal('os_lt', 18, 2)->default(0)->after('os_l0');
            $table->unsignedInteger('noa_lt')->default(0)->after('noa_l0');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_os_daily_aos', function (Blueprint $table) {
            $table->dropColumn(['os_l0','noa_l0','os_lt','noa_lt']);
        });
    }
};
