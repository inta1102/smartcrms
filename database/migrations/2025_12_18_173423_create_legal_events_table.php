<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('legal_case_id')->constrained('legal_cases')->cascadeOnDelete();
            $table->foreignId('legal_action_id')->nullable()->constrained('legal_actions')->nullOnDelete();

            $table->string('event_type', 50); // somasi_deadline, hearing, auction, document_due, follow_up, etc
            $table->string('title');

            $table->timestamp('event_at');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();

            $table->string('status', 20)->default('scheduled'); // scheduled/done/cancelled

            $table->timestamp('remind_at')->nullable();
            $table->json('remind_channels')->nullable(); // ["whatsapp","email"]

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['legal_case_id', 'event_at']);
            $table->index(['event_type', 'status']);
            $table->index('remind_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_events');
    }
};
