<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('action_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('npl_case_id')
                ->constrained()               // ke npl_cases.id
                ->cascadeOnDelete();

            $table->string('type', 50)->index();
            $table->string('title')->nullable();
            $table->text('notes')->nullable();

            $table->dateTime('scheduled_at')->index();
            $table->enum('status', ['pending', 'done', 'cancelled'])
                ->default('pending')
                ->index();

            $table->dateTime('completed_at')->nullable();
            $table->dateTime('last_notified_at')->nullable();

            // 🔴 HANYA kolom biasa, TANPA foreign key
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('action_schedules');
    }
};
