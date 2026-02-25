<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_kbl_monthlies', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('kbl_id');
            $table->date('period');                 // YYYY-MM-01
            $table->string('calc_mode', 20);         // realtime | eom

            // =========================
            // RAW METRICS (mini-check)
            // =========================

            // KYD (OS vs Target)
            $table->decimal('os_actual', 18, 2)->default(0);
            $table->decimal('os_target', 18, 2)->default(0);
            $table->decimal('kyd_ach_pct', 8, 2)->default(0); // OS/Target*100

            // Migrasi DPK cohort:
            // baseline prevMonth: kolek=2
            // current: kolek>=3
            $table->decimal('dpk_base_os', 18, 2)->default(0);      // SUM(prev.outstanding) prevMonth kolek=2
            $table->decimal('dpk_to_npl_os', 18, 2)->default(0);    // SUM(prev.outstanding) yang cur kolek>=3
            $table->decimal('dpk_mig_pct', 8, 2)->default(0);       // dpk_to_npl_os / dpk_base_os *100

            // NPL (ratio + achievement vs target)
            $table->decimal('npl_os', 18, 2)->default(0);           // OS kolek>=3 (current)
            $table->decimal('npl_ratio_pct', 8, 2)->default(0);     // npl_os / os_actual *100
            $table->decimal('npl_target_pct', 6, 2)->default(0);    // dari target
            $table->decimal('npl_ach_pct', 8, 2)->default(0);       // achievement vs target (rule kamu)

            // Pendapatan bunga (loan_installments)
            $table->decimal('interest_actual', 18, 2)->default(0);
            $table->decimal('interest_target', 18, 2)->default(0);
            $table->decimal('interest_ach_pct', 8, 2)->default(0);

            // Komunitas
            $table->unsignedInteger('community_actual')->default(0);
            $table->unsignedInteger('community_target')->default(0);

            // =========================
            // SCORES 1..6 + TOTAL WEIGHTED
            // =========================
            $table->unsignedTinyInteger('score_kyd')->default(0);
            $table->unsignedTinyInteger('score_dpk')->default(0);
            $table->unsignedTinyInteger('score_npl')->default(0);
            $table->unsignedTinyInteger('score_interest')->default(0);
            $table->unsignedTinyInteger('score_community')->default(0);

            $table->decimal('total_score_weighted', 6, 2)->default(0);

            // label status (Critical/Warning/On Track dsb)
            $table->string('status_label', 20)->nullable();

            // meta audit: scope ao_codes count, prevMonth/curMonth used, query notes, dll
            $table->longText('meta')->nullable();

            $table->timestamps();

            // unik per kabag + periode + mode (karena realtime & eom bisa sama-sama disimpan)
            $table->unique(['kbl_id', 'period', 'calc_mode'], 'uk_kbl_monthlies_kbl_period_mode');

            // indexes
            $table->index(['period', 'calc_mode'], 'idx_kbl_monthlies_period_mode');
            $table->index(['kbl_id', 'period'], 'idx_kbl_monthlies_kbl_period');

            $table->foreign('kbl_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_kbl_monthlies');
    }
};