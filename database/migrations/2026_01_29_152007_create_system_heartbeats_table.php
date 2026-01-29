<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('system_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('component')->unique(); // queue_runner, scheduler, etc
            $table->string('status')->default('ok'); // ok|warn|down
            $table->json('meta')->nullable();
            $table->timestamp('beat_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_heartbeats');
    }
};
