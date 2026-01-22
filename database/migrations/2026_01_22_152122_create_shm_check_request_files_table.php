<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shm_check_request_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('shm_check_requests')->cascadeOnDelete();

            $table->string('type', 30)->index(); // ktp, shm, sp, sk, signed_sp, signed_sk, result
            $table->string('file_path', 500);
            $table->string('original_name', 255)->nullable();

            $table->foreignId('uploaded_by')->constrained('users');
            $table->dateTime('uploaded_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['request_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_check_request_files');
    }
};
