<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_dekom_monthlies', function (Blueprint $table) {
            $table->id();

            // =========================
            // PERIODE & METADATA
            // =========================
            $table->date('period_month');                 // format: YYYY-MM-01
            $table->date('as_of_date')->nullable();       // posisi data terakhir yg dipakai
            $table->string('calc_mode', 20)->default('eom'); // eom | realtime | hybrid

            // =========================
            // PORTOFOLIO UTAMA
            // =========================
            $table->decimal('total_os', 18, 2)->default(0);
            $table->unsignedInteger('total_noa')->default(0);

            // =========================
            // FT BUCKET
            // =========================
            $table->decimal('ft0_os', 18, 2)->default(0);
            $table->unsignedInteger('ft0_noa')->default(0);

            $table->decimal('ft1_os', 18, 2)->default(0);
            $table->unsignedInteger('ft1_noa')->default(0);

            $table->decimal('ft2_os', 18, 2)->default(0);
            $table->unsignedInteger('ft2_noa')->default(0);

            $table->decimal('ft3_os', 18, 2)->default(0);
            $table->unsignedInteger('ft3_noa')->default(0);

            // =========================
            // KOLEK SUMMARY
            // =========================
            $table->decimal('l_os', 18, 2)->default(0);
            $table->unsignedInteger('l_noa')->default(0);

            $table->decimal('dpk_os', 18, 2)->default(0);
            $table->unsignedInteger('dpk_noa')->default(0);

            $table->decimal('kl_os', 18, 2)->default(0);
            $table->unsignedInteger('kl_noa')->default(0);

            $table->decimal('d_os', 18, 2)->default(0);
            $table->unsignedInteger('d_noa')->default(0);

            $table->decimal('m_os', 18, 2)->default(0);
            $table->unsignedInteger('m_noa')->default(0);

            // =========================
            // NPL & KKR
            // =========================
            $table->decimal('npl_os', 18, 2)->default(0);
            $table->unsignedInteger('npl_noa')->default(0);
            $table->decimal('npl_pct', 8, 4)->default(0);   // contoh: 25.8800
            $table->decimal('kkr_pct', 8, 4)->default(0);   // contoh: 68.0600

            // =========================
            // RESTRUKTURISASI
            // =========================
            $table->decimal('restr_os', 18, 2)->default(0);
            $table->unsignedInteger('restr_noa')->default(0);

            // =========================
            // REALISASI MTD / YTD
            // =========================
            $table->decimal('mtd_real_os', 18, 2)->default(0);
            $table->unsignedInteger('mtd_real_noa')->default(0);

            $table->decimal('ytd_real_os', 18, 2)->default(0);
            $table->unsignedInteger('ytd_real_noa')->default(0);

            // =========================
            // DAY PAST DUE WINDOWS
            // =========================
            $table->decimal('dpd6_os', 18, 2)->default(0);
            $table->unsignedInteger('dpd6_noa')->default(0);

            $table->decimal('dpd12_os', 18, 2)->default(0);
            $table->unsignedInteger('dpd12_noa')->default(0);

            // =========================
            // TARGET RBB SNAPSHOT
            // =========================
            $table->decimal('target_os', 18, 2)->default(0);
            $table->decimal('target_npl_pct', 8, 4)->default(0);
            $table->decimal('ach_os_pct', 8, 4)->default(0);

            // =========================
            // OPTIONAL DELTA / GROWTH
            // =========================
            $table->decimal('mom_os_growth_pct', 8, 4)->default(0);
            $table->decimal('yoy_os_growth_pct', 8, 4)->default(0);

            // =========================
            // EXTRA / AUDIT
            // =========================
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('period_month', 'uq_dashboard_dekom_monthlies_period_month');
            $table->index('as_of_date', 'idx_dashboard_dekom_monthlies_as_of_date');
            $table->index(['period_month', 'calc_mode'], 'idx_dashboard_dekom_monthlies_period_calc_mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_dekom_monthlies');
    }
};