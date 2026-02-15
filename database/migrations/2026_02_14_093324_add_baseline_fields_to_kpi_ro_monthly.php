<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_ro_monthly', function (Blueprint $table) {
            $table->tinyInteger('baseline_ok')->default(1)->after('calc_source_position_date');
            $table->string('baseline_note', 255)->nullable()->after('baseline_ok');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_ro_monthly', function (Blueprint $table) {
            $table->dropColumn(['baseline_ok', 'baseline_note']);
        });
    }
};
