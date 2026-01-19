<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();

            // jenis import, biar bisa dipakai untuk import lain juga
            $table->string('module', 50)->default('loans'); // contoh: loans, deposits, etc

            // tanggal posisi data yang diimport (yang dipilih user di form)
            $table->date('position_date')->nullable();

            // nama file yang diupload
            $table->string('file_name')->nullable();

            // ringkasan hasil
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);

            // status
            $table->enum('status', ['success', 'failed'])->default('success');
            $table->text('message')->nullable(); // error message / catatan

            // siapa yang import
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['module', 'created_at']);
            $table->index(['module', 'position_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
