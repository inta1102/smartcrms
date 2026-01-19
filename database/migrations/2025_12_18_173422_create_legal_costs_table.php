<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_costs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('legal_case_id')->constrained('legal_cases')->cascadeOnDelete();
            $table->foreignId('legal_action_id')->nullable()->constrained('legal_actions')->nullOnDelete();

            $table->string('cost_type', 50); // lawyer_fee, court_fee, auction_fee, appraisal_fee, transport, etc
            $table->decimal('amount', 18, 2);

            $table->date('cost_date');
            $table->text('description')->nullable();

            $table->string('paid_by', 30)->nullable(); // bank/debtor/recovered_deducted

           $table->unsignedBigInteger('evidence_doc_id')->nullable();
            $table->foreign('evidence_doc_id')
                ->references('id')
                ->on('legal_documents')
                ->nullOnDelete();


            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['legal_case_id', 'cost_date']);
            $table->index('legal_action_id');
            $table->index('cost_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_costs');
    }
};
