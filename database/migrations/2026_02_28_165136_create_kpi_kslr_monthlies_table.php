<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_kslr_monthlies', function (Blueprint $table) {
            $table->id();

            // ===============================
            // IDENTITAS
            // ===============================
            $table->date('period'); // YYYY-MM-01
            $table->unsignedBigInteger('kslr_id');
            $table->enum('calc_mode', ['realtime', 'eom'])->default('eom');

            // ===============================
            // RAW METRIC (%)
            // ===============================
            $table->decimal('kyd_ach_pct', 6, 2)->default(0);       // Achievement KYD (%)
            $table->decimal('dpk_mig_pct', 6, 2)->default(0);       // Migrasi DPK (%)
            $table->decimal('rr_pct', 6, 2)->default(0);            // Repayment Rate (%)
            $table->decimal('community_pct', 6, 2)->default(0);     // Activity vs target (%)

            // ===============================
            // SCORE 1–6
            // ===============================
            $table->unsignedTinyInteger('score_kyd')->default(0);
            $table->unsignedTinyInteger('score_dpk')->default(0);
            $table->unsignedTinyInteger('score_rr')->default(0);
            $table->unsignedTinyInteger('score_com')->default(0);

            // ===============================
            // TOTAL WEIGHTED
            // ===============================
            $table->decimal('total_score_weighted', 6, 2)->default(0);

            // ===============================
            // META / AUDIT
            // ===============================
            $table->json('meta')->nullable(); // scope_count, so_count, ro_count, dll

            $table->timestamps();

            // ===============================
            // INDEXING
            // ===============================
            $table->unique(['period', 'kslr_id', 'calc_mode'], 'uniq_kslr_period_mode');
            $table->index(['kslr_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_kslr_monthlies');
    }
};