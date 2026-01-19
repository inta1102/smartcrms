<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_cases', function (Blueprint $table) {
            $table->id();

            // 1:1 ke npl_cases (recommended)
            $table->foreignId('npl_case_id')->constrained('npl_cases')->cascadeOnDelete();
            $table->string('legal_case_no')->unique();

            $table->string('status', 50)->default('legal_init');
            $table->text('escalation_reason')->nullable();

            $table->foreignId('legal_owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('recommended_action')->nullable();
            $table->text('assessment_notes')->nullable();

            $table->decimal('total_outstanding_snapshot', 18, 2)->nullable();
            $table->decimal('total_collateral_value_snapshot', 18, 2)->nullable();

            $table->timestamp('closed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique('npl_case_id'); // enforce 1:1
            $table->index(['status', 'legal_owner_id']);
            $table->index('closed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_cases');
    }
};
