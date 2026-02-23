<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_ksbe_monthlies', function (Blueprint $table) {
            $table->bigIncrements('id');

            // identity
            $table->date('period'); // YYYY-MM-01
            $table->unsignedBigInteger('ksbe_user_id');

            // scope stats
            $table->unsignedInteger('scope_be_count')->default(0);
            $table->unsignedInteger('active_be_count')->default(0);
            $table->decimal('coverage_pct', 6, 2)->default(0);

            // recap target/actual
            $table->decimal('target_os_selesai', 18, 2)->default(0);
            $table->unsignedInteger('target_noa_selesai')->default(0);
            $table->decimal('target_bunga_masuk', 18, 2)->default(0);
            $table->decimal('target_denda_masuk', 18, 2)->default(0);

            $table->decimal('actual_os_selesai', 18, 2)->default(0);
            $table->unsignedInteger('actual_noa_selesai')->default(0);
            $table->decimal('actual_bunga_masuk', 18, 2)->default(0);
            $table->decimal('actual_denda_masuk', 18, 2)->default(0);

            // NPL stock info
            $table->decimal('os_npl_prev', 18, 2)->default(0);
            $table->decimal('os_npl_now', 18, 2)->default(0);
            $table->decimal('net_npl_drop', 18, 2)->default(0);
            $table->decimal('npl_drop_pct', 7, 2)->default(0);

            // performance (PI_scope)
            $table->decimal('ach_os', 7, 2)->default(0);
            $table->decimal('ach_noa', 7, 2)->default(0);
            $table->decimal('ach_bunga', 7, 2)->default(0);
            $table->decimal('ach_denda', 7, 2)->default(0);

            $table->unsignedTinyInteger('score_os')->default(1);     // 1..6
            $table->unsignedTinyInteger('score_noa')->default(1);
            $table->unsignedTinyInteger('score_bunga')->default(1);
            $table->unsignedTinyInteger('score_denda')->default(1);

            $table->decimal('pi_os', 6, 2)->default(0);
            $table->decimal('pi_noa', 6, 2)->default(0);
            $table->decimal('pi_bunga', 6, 2)->default(0);
            $table->decimal('pi_denda', 6, 2)->default(0);
            $table->decimal('pi_scope_total', 6, 2)->default(0);

            // stability (SI)
            $table->decimal('pi_stddev', 6, 3)->default(0);
            $table->unsignedInteger('bottom_be_count')->default(0);
            $table->decimal('bottom_pct', 6, 2)->default(0);

            $table->unsignedTinyInteger('si_coverage_score')->default(1);
            $table->unsignedTinyInteger('si_spread_score')->default(1);
            $table->unsignedTinyInteger('si_bottom_score')->default(1);
            $table->decimal('si_total', 6, 2)->default(0);

            // risk (RI)
            $table->unsignedTinyInteger('ri_score')->default(1);

            // improvement (II)
            $table->decimal('prev_pi_scope_total', 6, 2)->nullable();
            $table->decimal('delta_pi', 6, 2)->nullable();
            $table->unsignedTinyInteger('ii_score')->default(3);

            // final LI
            $table->decimal('li_total', 6, 2)->default(0);

            // insights
            $table->json('json_insights')->nullable();

            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            // constraints
            $table->unique(['period', 'ksbe_user_id'], 'uk_kpi_ksbe_period_user');
            $table->index(['ksbe_user_id', 'period'], 'ix_kpi_ksbe_user_period');

            // optional FK (kalau users pakai bigIncrements)
            // $table->foreign('ksbe_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_ksbe_monthlies');
    }
};