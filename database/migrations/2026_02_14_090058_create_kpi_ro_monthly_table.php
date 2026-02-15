<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_ro_monthly', function (Blueprint $table) {
            $table->id();

            // periode KPI (YYYY-MM-01)
            $table->date('period_month');

            $table->string('branch_code', 20)->nullable();
            $table->string('ao_code', 50);

            /*
            |--------------------------------------------------------------------------
            | TOP UP
            |--------------------------------------------------------------------------
            */
            $table->decimal('topup_realisasi', 18, 2)->default(0);
            $table->decimal('topup_target', 18, 2)->default(750000000);
            $table->decimal('topup_pct', 8, 2)->default(0);
            $table->tinyInteger('topup_score')->default(0);

            /*
            |--------------------------------------------------------------------------
            | REPAYMENT RATE
            |--------------------------------------------------------------------------
            */
            $table->decimal('repayment_rate', 8, 4)->default(0); // ex: 0.9750
            $table->decimal('repayment_pct', 8, 2)->default(0);
            $table->tinyInteger('repayment_score')->default(0);

            /*
            |--------------------------------------------------------------------------
            | NOA PENGEMBANGAN
            |--------------------------------------------------------------------------
            */
            $table->integer('noa_realisasi')->default(0);
            $table->integer('noa_target')->default(2);
            $table->decimal('noa_pct', 8, 2)->default(0);
            $table->tinyInteger('noa_score')->default(0);

            /*
            |--------------------------------------------------------------------------
            | PEMBURUKAN DPK
            |--------------------------------------------------------------------------
            */
            $table->decimal('dpk_pct', 8, 4)->default(0); // % migrasi
            $table->tinyInteger('dpk_score')->default(0);

            /*
            |--------------------------------------------------------------------------
            | TOTAL KPI
            |--------------------------------------------------------------------------
            */
            $table->decimal('total_score_weighted', 8, 2)->default(0);

            /*
            |--------------------------------------------------------------------------
            | META
            |--------------------------------------------------------------------------
            */
            $table->enum('calc_mode', ['realtime', 'eom'])->default('realtime');
            $table->date('start_snapshot_month')->nullable();
            $table->date('end_snapshot_month')->nullable();
            $table->date('calc_source_position_date')->nullable();

            $table->timestamp('locked_at')->nullable(); // jika EOM sudah final

            $table->timestamps();

            // UNIQUE constraint: 1 RO per bulan
            $table->unique(['period_month', 'ao_code'], 'uniq_kpi_ro_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_ro_monthly');
    }
};
