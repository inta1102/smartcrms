<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('legal_case_id')->constrained('legal_cases')->cascadeOnDelete();

            $table->string('action_type', 50); // somasi, ht_execution, civil_lawsuit, etc
            $table->unsignedInteger('sequence_no')->default(1);

            $table->string('status', 30)->default('draft'); // draft/submitted/in_progress/waiting/completed/cancelled/failed

            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();

            // External refs
            $table->string('external_ref_no')->nullable();
            $table->string('external_institution')->nullable();

            // Handler info
            $table->string('handler_type', 20)->nullable(); // internal/law_firm
            $table->string('law_firm_name')->nullable();
            $table->string('handler_name')->nullable();
            $table->string('handler_phone', 30)->nullable();

            $table->string('summary')->nullable();
            $table->text('notes')->nullable();

            $table->string('result_type', 30)->nullable(); // recovered_full/partial/no_recovery/escalated/settlement
            $table->decimal('recovery_amount', 18, 2)->default(0);
            $table->date('recovery_date')->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['legal_case_id', 'sequence_no']);
            $table->index(['legal_case_id', 'action_type']);
            $table->index(['status', 'action_type']);
            $table->index(['start_at', 'end_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_actions');
    }
};
