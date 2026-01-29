<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('queue_heartbeats', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique(); // contoh: queue_runner
            $t->timestamp('last_seen_at')->nullable();
            $t->unsignedInteger('last_run_processed')->default(0); // berapa job diproses di run terakhir
            $t->unsignedInteger('last_run_failed')->default(0);
            $t->unsignedInteger('last_run_ms')->default(0);
            $t->json('meta')->nullable(); // host, php, queue, dsb
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_heartbeats');
    }
};
