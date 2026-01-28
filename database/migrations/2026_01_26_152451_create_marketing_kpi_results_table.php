<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketing_kpi_results', function (Blueprint $table) {
            $table->id();
            $table->date('period');
            $table->foreignId('user_id')->constrained('users');

            // copy target
            $table->decimal('target_os_growth', 18, 2)->default(0);
            $table->unsignedInteger('target_noa')->default(0);

            // realization
            $table->decimal('real_os_growth', 18, 2)->default(0);
            $table->unsignedInteger('real_noa_new')->default(0);

            // ratios & scores
            $table->decimal('ratio_os', 8, 4)->default(0);
            $table->decimal('ratio_noa', 8, 4)->default(0);

            $table->decimal('score_os', 8, 2)->default(0);
            $table->decimal('score_noa', 8, 2)->default(0);
            $table->decimal('score_total', 8, 2)->default(0);

            $table->decimal('cap_ratio', 4, 2)->default(1.20);

            $table->string('status', 20)->default('DRAFT'); // DRAFT|FINALIZED
            $table->foreignId('calculated_by')->nullable()->constrained('users');
            $table->dateTime('calculated_at')->nullable();

            $table->boolean('is_locked')->default(false);

            $table->timestamps();

            $table->unique(['period', 'user_id']);
            $table->index(['period']);
            $table->index(['score_total']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_kpi_results');
    }
};
