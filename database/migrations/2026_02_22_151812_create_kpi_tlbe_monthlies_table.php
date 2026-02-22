<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_tlbe_monthlies', function (Blueprint $table) {
            $table->id();

            $table->date('period');                 // YYYY-MM-01
            $table->unsignedBigInteger('tlbe_user_id');

            // scope info
            $table->unsignedInteger('scope_count')->default(0);

            // aggregated targets
            $table->decimal('target_os_sum', 18, 2)->default(0);
            $table->unsignedInteger('target_noa_sum')->default(0);
            $table->decimal('target_bunga_sum', 18, 2)->default(0);
            $table->decimal('target_denda_sum', 18, 2)->default(0);

            // aggregated actuals
            $table->decimal('actual_os_sum', 18, 2)->default(0);
            $table->unsignedInteger('actual_noa_sum')->default(0);
            $table->decimal('actual_bunga_sum', 18, 2)->default(0);
            $table->decimal('actual_denda_sum', 18, 2)->default(0);

            // achievement %
            $table->decimal('ach_os_pct', 8, 2)->default(0);
            $table->decimal('ach_noa_pct', 8, 2)->default(0);
            $table->decimal('ach_bunga_pct', 8, 2)->default(0);
            $table->decimal('ach_denda_pct', 8, 2)->default(0);

            // score 1..6 (atau 1..5 kalau kamu pakai)
            $table->unsignedTinyInteger('score_os')->default(1);
            $table->unsignedTinyInteger('score_noa')->default(1);
            $table->unsignedTinyInteger('score_bunga')->default(1);
            $table->unsignedTinyInteger('score_denda')->default(1);

            // pi per metric
            $table->decimal('pi_os', 6, 2)->default(0);
            $table->decimal('pi_noa', 6, 2)->default(0);
            $table->decimal('pi_bunga', 6, 2)->default(0);
            $table->decimal('pi_denda', 6, 2)->default(0);
            $table->decimal('team_pi', 6, 2)->default(0); // sum pi metric

            // leadership indexes
            $table->decimal('avg_pi_be', 6, 2)->default(0);
            $table->decimal('coverage_pct', 6, 2)->default(0);      // 0..100
            $table->decimal('consistency_idx', 6, 2)->default(0);   // 0..1

            // final
            $table->decimal('total_pi', 6, 2)->default(0);

            // calc meta
            $table->string('calc_mode', 20)->default('mixed'); // mixed|realtime|eom
            $table->string('status', 20)->nullable();          // draft/submitted (optional)

            $table->timestamps();

            $table->unique(['period', 'tlbe_user_id']);
            $table->index(['period']);
            $table->index(['tlbe_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_tlbe_monthlies');
    }
};