<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_fe_monthlies', function (Blueprint $table) {
            $table->id();

            // periode KPI (1st day of month)
            $table->date('period')->index();

            // mode hitung: realtime (bulan berjalan) / eom (bulan lalu ke bawah)
            $table->string('calc_mode', 10)->default('realtime')->index(); // realtime|eom

            // FE identity
            $table->unsignedBigInteger('fe_user_id')->index();
            $table->string('ao_code', 20)->index();

            // ====== BASELINE / INPUT METRIC ======
            $table->decimal('os_kol2_awal', 18, 2)->default(0);
            $table->decimal('os_kol2_akhir', 18, 2)->default(0);
            $table->decimal('os_kol2_turun', 18, 2)->default(0); // os_awal - os_akhir (min 0)

            $table->decimal('migrasi_npl_os', 18, 2)->default(0); // OS yang migrasi ke kol>=3
            $table->decimal('migrasi_npl_pct', 10, 4)->default(0); // migrasi_os / os_awal * 100

            $table->decimal('penalty_paid_total', 18, 2)->default(0); // sum denda masuk

            // ====== TARGET SNAPSHOT (copied at calc time) ======
            $table->decimal('target_os_turun_kol2', 18, 2)->default(0);
            $table->decimal('target_migrasi_npl_pct', 8, 4)->default(0.3000);
            $table->decimal('target_penalty_paid', 18, 2)->default(0);

            // ====== ACHIEVEMENT (for display) ======
            $table->decimal('ach_os_turun_pct', 10, 2)->default(0);      // os_turun / target_os_turun * 100
            $table->decimal('ach_migrasi_pct', 10, 2)->default(0);       // reverse achievement (optional)
            $table->decimal('ach_penalty_pct', 10, 2)->default(0);       // penalty / target_penalty * 100

            // ====== SCORE ======
            $table->decimal('score_os_turun', 6, 2)->default(0); // 1..6
            $table->decimal('score_migrasi', 6, 2)->default(0);  // 1..6 reverse
            $table->decimal('score_penalty', 6, 2)->default(0);  // 1..6

            // ====== WEIGHTED SCORE ======
            $table->decimal('pi_os_turun', 8, 2)->default(0);    // score * 0.40
            $table->decimal('pi_migrasi', 8, 2)->default(0);     // score * 0.40
            $table->decimal('pi_penalty', 8, 2)->default(0);     // score * 0.20
            $table->decimal('total_score_weighted', 8, 2)->default(0);

            // ====== BASELINE / DATA QUALITY FLAG ======
            $table->boolean('baseline_ok')->default(true);
            $table->string('baseline_note', 255)->nullable();

            // audit calc
            $table->unsignedBigInteger('calculated_by')->nullable()->index(); // user id (KBL/Admin KPI)
            $table->timestamp('calculated_at')->nullable();

            $table->timestamps();

            // 1 FE 1 periode 1 mode = 1 row
            $table->unique(['period', 'calc_mode', 'fe_user_id'], 'uq_kpi_fe_monthlies_period_mode_fe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_fe_monthlies');
    }
};
