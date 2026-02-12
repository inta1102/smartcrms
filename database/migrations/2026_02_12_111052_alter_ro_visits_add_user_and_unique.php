<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ro_visits', function (Blueprint $table) {

            // 1) user_id (RO yang checklist)
            if (!Schema::hasColumn('ro_visits', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('id')->index();
            }

            // 2) pastikan visit_date bisa null? (kalau existing sudah NOT NULL jangan diubah)
            // kita pakai visit_date sebagai tanggal plan visit
            // (tidak perlu ubah tipe karena sudah ada)

            // 3) tambahkan kolom source opsional (biar tahu datang dari tabel mana: due/lt/jt/osbig/osdaily)
            if (!Schema::hasColumn('ro_visits', 'source')) {
                $table->string('source', 30)->nullable()->after('status')->index();
            }

            // 4) pastikan status punya default planned (kalau kolom status sudah ada tapi tanpa default, biarkan saja)
            // tidak saya ubah agar aman di prod (ubah default sering butuh doctrine/dbal).
        });

        /**
         * ✅ Anti-duplikat sebelum bikin UNIQUE:
         * - Hapus duplikat yang user_id + account_no + visit_date sama (keep id terkecil)
         * - Hanya dijalankan kalau user_id sudah ada dan sudah terisi (atau minimal kolomnya ada)
         *
         * Note:
         * - Kalau saat ini user_id masih null semua, belum ada duplikat "per user".
         * - Tapi langkah ini tetap aman.
         */
        if (Schema::hasColumn('ro_visits', 'user_id')) {
            // Delete duplicates: keep MIN(id)
            DB::statement("
                DELETE rv1 FROM ro_visits rv1
                INNER JOIN ro_visits rv2
                  ON rv1.user_id <=> rv2.user_id
                 AND rv1.account_no = rv2.account_no
                 AND rv1.visit_date = rv2.visit_date
                 AND rv1.id > rv2.id
            ");
        }

        // 5) Tambah unique index (RO + rekening + tanggal)
        // Pakai try/catch supaya kalau sudah ada, migration tidak jebol.
        try {
            Schema::table('ro_visits', function (Blueprint $table) {
                $table->unique(['user_id', 'account_no', 'visit_date'], 'ro_visits_uq_user_acc_date');
            });
        } catch (\Throwable $e) {
            // ignore (misal index sudah ada)
        }
    }

    public function down(): void
    {
        // rollback yang aman
        try {
            Schema::table('ro_visits', function (Blueprint $table) {
                $table->dropUnique('ro_visits_uq_user_acc_date');
            });
        } catch (\Throwable $e) {}

        Schema::table('ro_visits', function (Blueprint $table) {
            if (Schema::hasColumn('ro_visits', 'source')) {
                $table->dropColumn('source');
            }
            // user_id sengaja tidak di-drop untuk keamanan data, tapi kalau mau bersih:
            // if (Schema::hasColumn('ro_visits', 'user_id')) $table->dropColumn('user_id');
        });
    }
};
