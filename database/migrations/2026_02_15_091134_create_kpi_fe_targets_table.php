<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_fe_targets', function (Blueprint $table) {
            $table->id();

            // periode KPI (pakai 1st day of month)
            $table->date('period');

            // FE identity (link ke users)
            $table->unsignedBigInteger('fe_user_id')->index();

            // mapping loan via ao_code
            $table->string('ao_code', 20)->index();

            // target KPI FE
            $table->decimal('target_os_turun_kol2', 18, 2)->default(0);   // target nett penurunan OS kol2 (nominal)
            $table->decimal('target_migrasi_npl_pct', 8, 4)->default(0.3000); // target migrasi (percent), default 0.3
            $table->decimal('target_penalty_paid', 18, 2)->default(0);   // target denda masuk (nominal)

            // audit trail
            $table->unsignedBigInteger('created_by')->nullable()->index(); // user id (KBL/Admin KPI)
            $table->timestamps();

            // unique: 1 FE 1 periode 1 target
            $table->unique(['period', 'fe_user_id'], 'uq_kpi_fe_targets_period_fe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_fe_targets');
    }
};
