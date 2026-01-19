<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('job_logs', function (Blueprint $table) {
            $table->id();

            // Kunci job (untuk grouping)
            $table->string('job_key', 80);        // ex: sync_users_api

            // Status: success|failed
            $table->string('status', 20);

            // Ringkasan performa
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('count')->nullable(); // jumlah row upsert/processed

            // Pesan singkat (error atau info)
            $table->text('message')->nullable();

            // Meta detail (since, next_since, loops, url, dll)
            $table->json('meta')->nullable();

            // waktu run
            $table->timestamp('ran_at')->useCurrent();

            $table->timestamps();

            $table->index(['job_key', 'status', 'ran_at']);
            $table->index(['job_key', 'ran_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_logs');
    }
};
