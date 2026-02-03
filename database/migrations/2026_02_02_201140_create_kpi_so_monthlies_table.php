<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_so_monthlies', function (Blueprint $table) {
            $table->id();
            $table->date('period');
            $table->unsignedBigInteger('user_id');
            $table->string('ao_code', 20)->nullable();

            $table->unsignedBigInteger('target_id')->nullable();

            // Disbursement-based (SO)
            $table->bigInteger('os_disbursement')->default(0);
            $table->integer('noa_disbursement')->default(0);

            // Repayment Rate (3-month window for new loans)
            $table->integer('rr_due_count')->default(0);
            $table->integer('rr_paid_ontime_count')->default(0);
            $table->decimal('rr_pct', 5, 2)->default(0);

            // Activity
            $table->integer('activity_target')->default(0);
            $table->integer('activity_actual')->default(0);
            $table->decimal('activity_pct', 5, 2)->default(0);

            $table->boolean('is_final')->default(false);
            $table->dateTime('calculated_at')->nullable();

            // Scores
            $table->decimal('score_os', 6, 2)->default(0);
            $table->decimal('score_noa', 6, 2)->default(0);
            $table->decimal('score_rr', 6, 2)->default(0);
            $table->decimal('score_activity', 6, 2)->default(0);
            $table->decimal('score_total', 7, 2)->default(0);

            $table->timestamps();

            $table->unique(['period', 'user_id']);
            $table->index(['period', 'ao_code']);
            $table->index(['period', 'score_total']);
            $table->index(['period', 'os_disbursement']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('target_id')->references('id')->on('kpi_so_targets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_so_monthlies');
    }
};
