<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {

            // 1) doc_type (kalau belum ada)
            if (!Schema::hasColumn('legal_documents', 'doc_type')) {
                $table->string('doc_type', 50)->nullable()->after('legal_action_id');
                // contoh isi: 'sertifikat', 'apht', dst.
            }

            // 2) file info (kalau field lama beda, kita tidak ubah, hanya tambah yang belum ada)
            if (!Schema::hasColumn('legal_documents', 'file_path')) {
                $table->string('file_path')->nullable()->after('doc_type');
            }
            if (!Schema::hasColumn('legal_documents', 'file_name')) {
                $table->string('file_name')->nullable()->after('file_path');
            }
            if (!Schema::hasColumn('legal_documents', 'mime')) {
                $table->string('mime', 100)->nullable()->after('file_name');
            }
            if (!Schema::hasColumn('legal_documents', 'size')) {
                $table->unsignedBigInteger('size')->nullable()->after('mime');
            }

            // 3) status dokumen
            if (!Schema::hasColumn('legal_documents', 'status')) {
                $table->string('status', 20)->default('uploaded')->after('size');
                // values: uploaded|verified|rejected
            }

            // 4) upload audit
            if (!Schema::hasColumn('legal_documents', 'uploaded_by')) {
                $table->foreignId('uploaded_by')->nullable()->after('status')
                    ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('legal_documents', 'uploaded_at')) {
                $table->timestamp('uploaded_at')->nullable()->after('uploaded_by');
            }

            // 5) verifikasi audit
            if (!Schema::hasColumn('legal_documents', 'verified_by')) {
                $table->foreignId('verified_by')->nullable()->after('uploaded_at')
                    ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('legal_documents', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('verified_by');
            }
            if (!Schema::hasColumn('legal_documents', 'verify_notes')) {
                $table->text('verify_notes')->nullable()->after('verified_at');
            }

            // 6) reject audit
            if (!Schema::hasColumn('legal_documents', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('verify_notes')
                    ->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('legal_documents', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            }
            if (!Schema::hasColumn('legal_documents', 'reject_notes')) {
                $table->text('reject_notes')->nullable()->after('rejected_at');
            }

            // 7) index untuk performa checklist
            // (buat hanya jika belum ada kombinasi index ini)
            // Laravel tidak punya "hasIndex" bawaan yang konsisten lintas DB,
            // jadi kita buat index dengan nama yang spesifik dan aman dicoba.
            // Kalau ternyata sudah ada index dengan nama sama, migration akan error.
            // Maka kita "guard" dengan try/catch tidak tersedia di schema builder.
            // Solusi aman: pakai nama index yang unik dan jarang ada.
            $indexName = 'legal_docs_action_doctype_status_idx';
            // Kita cek dengan cara sederhana: tambahkan hanya jika 3 kolom tersebut ada.
            if (
                Schema::hasColumn('legal_documents', 'legal_action_id') &&
                Schema::hasColumn('legal_documents', 'doc_type') &&
                Schema::hasColumn('legal_documents', 'status')
            ) {
                // Untuk menghindari error kalau index sudah ada tapi beda nama,
                // kamu bisa comment baris ini jika migration pertama kali error karena index.
                $table->index(['legal_action_id', 'doc_type', 'status'], $indexName);
            }
        });
    }

    public function down(): void
    {
        Schema::table('legal_documents', function (Blueprint $table) {

            // drop index (kalau ada)
            // Jika index tidak ada, dropIndex akan error.
            // Jadi biasanya down dipakai jarang; kalau kamu ingin aman 100%,
            // kita bisa skip dropIndex. Tapi aku tetap sertakan dengan catatan.
            $indexName = 'legal_docs_action_doctype_status_idx';
            try {
                $table->dropIndex($indexName);
            } catch (\Throwable $e) {
                // ignore
            }

            // drop FK dulu baru kolom
            foreach (['uploaded_by', 'verified_by', 'rejected_by'] as $fkCol) {
                if (Schema::hasColumn('legal_documents', $fkCol)) {
                    try {
                        $table->dropForeign([$fkCol]);
                    } catch (\Throwable $e) {
                        // ignore
                    }
                }
            }

            // kolom-kolom tambahan (drop hanya jika ada)
            $cols = [
                'reject_notes', 'rejected_at', 'rejected_by',
                'verify_notes', 'verified_at', 'verified_by',
                'uploaded_at', 'uploaded_by',
                'status',
                'size', 'mime', 'file_name',
                // 'file_path', // ⚠️ aku tidak drop file_path/doc_type by default karena bisa jadi kolom inti lama
                // 'doc_type',
            ];

            foreach ($cols as $col) {
                if (Schema::hasColumn('legal_documents', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
