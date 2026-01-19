<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_action_proposals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('npl_case_id')->constrained('npl_cases')->cascadeOnDelete();

            $table->string('action_type', 50); // somasi, ht_execution, ...
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 30)->default('draft');
            // draft | submitted | approved_tl | rejected_tl | approved_kasi | rejected_kasi | executed | cancelled

            $table->foreignId('proposed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('submitted_at')->nullable();

            $table->foreignId('approved_tl_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_tl_at')->nullable();
            $table->text('approved_tl_notes')->nullable();

            $table->foreignId('approved_kasi_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_kasi_at')->nullable();
            $table->text('approved_kasi_notes')->nullable();

            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();

            $table->foreignId('legal_action_id')->nullable()->constrained('legal_actions')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'action_type']);
            $table->index(['npl_case_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_action_proposals');
    }
};
