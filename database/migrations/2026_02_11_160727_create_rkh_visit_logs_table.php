<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rkh_visit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rkh_detail_id')->constrained('rkh_details')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');

            $table->dateTime('visited_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('location_note', 255)->nullable();

            $table->longText('notes');
            $table->string('agreement', 255)->nullable();
            $table->string('next_action', 255)->nullable();
            $table->date('next_action_due')->nullable();
            $table->string('photo_path')->nullable();

            // tracking promote ke timeline penanganan
            $table->timestamp('promoted_at')->nullable();
            $table->unsignedBigInteger('promoted_to_case_id')->nullable()->index();
            $table->unsignedBigInteger('promoted_action_id')->nullable()->index();

            $table->timestamps();

            $table->index(['rkh_detail_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rkh_visit_logs');
    }
};
