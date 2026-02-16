<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kpi_ao_monthlies', function (Blueprint $table) {
            $table->string('scheme', 20)->default('LEGACY')->after('data_source'); 
            // contoh value: 'AO_UMKM' atau 'LEGACY'
        });
    }

    public function down(): void
    {
        Schema::table('kpi_ao_monthlies', function (Blueprint $table) {
            $table->dropColumn('scheme');
        });
    }
};
