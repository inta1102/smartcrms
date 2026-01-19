<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('schedule_update_logs', function (Blueprint $table) {
            $table->id();

            $table->date('position_date')->index();

            $table->enum('status', ['running','success','failed'])->default('running')->index();
            $table->string('batch_id', 64)->nullable()->index();

            $table->unsignedInteger('total_cases')->default(0);
            $table->unsignedInteger('scheduled_cases')->default(0);
            $table->unsignedInteger('cancelled_cases')->default(0);
            $table->unsignedInteger('failed_cases')->default(0);

            $table->text('message')->nullable();

            $table->foreignId('run_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_update_logs');
    }
};
