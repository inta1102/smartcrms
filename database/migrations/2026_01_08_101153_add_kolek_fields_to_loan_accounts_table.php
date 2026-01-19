<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            // ✅ dari Excel: "jenis_agunan" (contoh: 6, 9)
            if (!Schema::hasColumn('loan_accounts', 'jenis_agunan')) {
                $table->unsignedSmallInteger('jenis_agunan')
                    ->nullable()
                    ->after('kolek')
                    ->comment('Kode jenis agunan dari CBS/Excel. Contoh: 6, 9');
            }

            // ✅ dari Excel: "tgl_kolek" (tanggal mulai kolek berjalan; penting utk usia macet)
            if (!Schema::hasColumn('loan_accounts', 'tgl_kolek')) {
                $table->date('tgl_kolek')
                    ->nullable()
                    ->after('jenis_agunan')
                    ->comment('Tanggal mulai kolek (acuan usia kolek/macet).');
            }

            // ✅ dari Excel: "keterangan_sandi" (mis: TANAH, BANGUNAN DAN/ATAU RUMAH)
            if (!Schema::hasColumn('loan_accounts', 'keterangan_sandi')) {
                $table->string('keterangan_sandi', 255)
                    ->nullable()
                    ->after('tgl_kolek')
                    ->comment('Keterangan sandi/jenis agunan dari CBS/Excel.');
            }

            // ✅ opsional: dari Excel: "cadangan_ppap" (kalau memang mau disimpan)
            if (!Schema::hasColumn('loan_accounts', 'cadangan_ppap')) {
                $table->unsignedBigInteger('cadangan_ppap')
                    ->nullable()
                    ->after('keterangan_sandi')
                    ->comment('Cadangan PPAP dari CBS/Excel (nominal).');
            }

            // Index untuk kebutuhan EWS query (kolek=5 + jenis_agunan + tgl_kolek)
            // (index aman walau data lama kosong karena kolom nullable)
            if (!Schema::hasColumn('loan_accounts', 'jenis_agunan') || !Schema::hasColumn('loan_accounts', 'tgl_kolek')) {
                // no-op (sudah ada)
            } else {
                $table->index(['kolek', 'jenis_agunan', 'tgl_kolek'], 'idx_loan_accounts_kolek_agunan_tgl');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            // drop index dulu kalau ada
            if (Schema::hasColumn('loan_accounts', 'kolek')
                && Schema::hasColumn('loan_accounts', 'jenis_agunan')
                && Schema::hasColumn('loan_accounts', 'tgl_kolek')) {
                $table->dropIndex('idx_loan_accounts_kolek_agunan_tgl');
            }

            if (Schema::hasColumn('loan_accounts', 'cadangan_ppap')) {
                $table->dropColumn('cadangan_ppap');
            }
            if (Schema::hasColumn('loan_accounts', 'keterangan_sandi')) {
                $table->dropColumn('keterangan_sandi');
            }
            if (Schema::hasColumn('loan_accounts', 'tgl_kolek')) {
                $table->dropColumn('tgl_kolek');
            }
            if (Schema::hasColumn('loan_accounts', 'jenis_agunan')) {
                $table->dropColumn('jenis_agunan');
            }
        });
    }
};
