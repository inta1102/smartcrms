<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_action_status_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('legal_action_id')->constrained('legal_actions')->cascadeOnDelete();

            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->timestamp('changed_at');

            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();

            $table->timestamps();

            $table->index(['legal_action_id', 'changed_at']);
            $table->index('to_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_action_status_logs');
    }
};
