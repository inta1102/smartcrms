<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kpi_be_monthlies')) return;

        Schema::create('kpi_be_monthlies', function (Blueprint $table) {
            $table->id();

            // period: tanggal awal bulan YYYY-MM-01
            $table->date('period')->index();

            $table->unsignedBigInteger('be_user_id')->index();

            // ======================
            // ACTUAL (hasil hitung)
            // ======================
            $table->decimal('actual_os_selesai', 18, 2)->default(0);
            $table->unsignedInteger('actual_noa_selesai')->default(0);
            $table->decimal('actual_bunga_masuk', 18, 2)->default(0);
            $table->decimal('actual_denda_masuk', 18, 2)->default(0);

            // ======================
            // SCORE (1..6)
            // ======================
            $table->unsignedTinyInteger('score_os')->default(1);
            $table->unsignedTinyInteger('score_noa')->default(1);
            $table->unsignedTinyInteger('score_bunga')->default(1);
            $table->unsignedTinyInteger('score_denda')->default(1);

            // ======================
            // PI (skor * bobot)
            // ======================
            $table->decimal('pi_os', 6, 2)->default(0);
            $table->decimal('pi_noa', 6, 2)->default(0);
            $table->decimal('pi_bunga', 6, 2)->default(0);
            $table->decimal('pi_denda', 6, 2)->default(0);
            $table->decimal('total_pi', 6, 2)->default(0);

            // ======================
            // INFO kontrol (bukan KPI utama)
            // ======================
            $table->decimal('os_npl_prev', 18, 2)->default(0);
            $table->decimal('os_npl_now', 18, 2)->default(0);
            $table->decimal('net_npl_drop', 18, 2)->default(0);

            // ======================
            // Approval workflow (samakan pola FE)
            // ======================
            $table->string('status', 20)->default('draft'); // draft|submitted|approved|rejected
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();

            $table->text('approval_note')->nullable();

            $table->timestamps();

            $table->unique(['period', 'be_user_id'], 'uq_kpi_be_monthlies_period_user');

            // FK users
            $table->foreign('be_user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_be_monthlies');
    }
};
