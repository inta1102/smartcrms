<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('visit_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rkh_detail_id')->constrained('rkh_details')->cascadeOnDelete();
            $table->dateTime('scheduled_at');
            $table->string('title');
            $table->text('notes')->nullable();
            $table->enum('status', ['planned','done','canceled'])->default('planned');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->index(['rkh_detail_id','scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_schedules');
    }
};
