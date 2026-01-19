<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();

            // Relasi utama
            $table->foreignId('legal_case_id')
                  ->constrained('legal_cases')
                  ->cascadeOnDelete();

            $table->foreignId('legal_action_id')
                  ->nullable()
                  ->constrained('legal_actions')
                  ->nullOnDelete();

            // Metadata dokumen
            $table->string('doc_type', 50);          // sp1, sp2, sp3, spt, somasi, gugatan, putusan, kuitansi, dll
            $table->string('title')->nullable();     // judul/label dokumen
            $table->string('doc_no', 100)->nullable(); // nomor surat/dokumen bila ada
            $table->date('doc_date')->nullable();    // tanggal dokumen
            $table->text('notes')->nullable();

            // File storage
            $table->string('file_path');             // path di storage/public atau storage/app
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable(); // bytes

            // Audit
            $table->foreignId('uploaded_by')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->timestamps();

            // Index penting
            $table->index(['legal_case_id', 'doc_type']);
            $table->index(['legal_action_id', 'doc_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
