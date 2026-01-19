<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('visit_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('npl_case_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('action_schedule_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // ⬇️ TANPA FOREIGN KEY KE USERS
            $table->unsignedBigInteger('user_id')->nullable();

            $table->dateTime('visited_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_note')->nullable();

            $table->text('notes')->nullable();
            $table->string('agreement')->nullable();
            $table->string('photo_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_logs');
    }
};
