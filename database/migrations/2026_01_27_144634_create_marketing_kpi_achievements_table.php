<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketing_kpi_achievements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('target_id')
                ->constrained('marketing_kpi_targets')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Period disimpan YYYY-MM-01
            $table->date('period')->index();

            // ===== Snapshot / Source info =====
            $table->string('os_source_now', 20)->default('loan_accounts');   // snapshot|loan_accounts|none
            $table->string('os_source_prev', 20)->default('loan_accounts');
            $table->date('position_date_now')->nullable();
            $table->date('position_date_prev')->nullable();
            $table->boolean('is_final')->default(false)->index(); // final kalau snapshot tersedia utk now & prev

            // ===== Values =====
            $table->decimal('os_end_now', 18, 2)->default(0);
            $table->decimal('os_end_prev', 18, 2)->default(0);
            $table->decimal('os_growth', 18, 2)->default(0);

            $table->unsignedInteger('noa_end_now')->default(0);
            $table->unsignedInteger('noa_end_prev')->default(0);
            $table->integer('noa_growth')->default(0); // proxy "debitur baru" = growth NOA

            // ===== KPI Achievement =====
            $table->decimal('os_ach_pct', 8, 2)->default(0);   // (growth/target)*100
            $table->decimal('noa_ach_pct', 8, 2)->default(0);

            $table->decimal('score_os', 8, 2)->default(0);     // clamp 0..120 (default)
            $table->decimal('score_noa', 8, 2)->default(0);
            $table->decimal('score_total', 8, 2)->default(0);

            $table->timestamps();

            $table->unique(['target_id'], 'uq_marketing_kpi_achievements_target');
            $table->index(['user_id', 'period'], 'idx_mkt_kpi_ach_user_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_kpi_achievements');
    }
};
