<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shm_check_request_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('shm_check_requests')->cascadeOnDelete();
            $table->foreignId('actor_id')->constrained('users');

            $table->string('action', 50)->index();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();

            $table->text('message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_check_request_logs');
    }
};
