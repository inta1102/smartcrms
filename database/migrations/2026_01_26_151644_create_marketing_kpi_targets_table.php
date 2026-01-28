<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketing_kpi_targets', function (Blueprint $table) {
            $table->id();
            $table->date('period');                 // pakai tanggal 1 setiap bulan
            $table->foreignId('user_id')->constrained('users');

            $table->string('branch_code', 10)->nullable();

            $table->decimal('target_os_growth', 18, 2)->default(0);
            $table->unsignedInteger('target_noa')->default(0);

            $table->unsignedTinyInteger('weight_os')->default(60);
            $table->unsignedTinyInteger('weight_noa')->default(40);

            $table->string('status', 20)->default('DRAFT'); // DRAFT|SUBMITTED|APPROVED|REJECTED

            $table->foreignId('proposed_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->dateTime('approved_at')->nullable();

            $table->string('notes', 500)->nullable();
            $table->boolean('is_locked')->default(false);

            $table->timestamps();

            $table->unique(['period', 'user_id']);
            $table->index(['period']);
            $table->index(['user_id']);
            $table->index(['branch_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_kpi_targets');
    }
};

