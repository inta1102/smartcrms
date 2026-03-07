<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_os_daily_aos', function (Blueprint $table) {
            $table->decimal('os_potensi', 18, 2)->default(0)->after('os_m');
            $table->unsignedInteger('noa_potensi')->default(0)->after('noa_m');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_os_daily_aos', function (Blueprint $table) {
            $table->dropColumn([
                'os_potensi',
                'noa_potensi',
            ]);
        });
    }
};