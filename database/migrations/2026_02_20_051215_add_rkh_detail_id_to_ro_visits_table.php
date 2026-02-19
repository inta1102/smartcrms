<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ro_visits', function (Blueprint $table) {
            // 1) Link ke RKH Detail (nullable biar backward compatible)
            if (!Schema::hasColumn('ro_visits', 'rkh_detail_id')) {
                $table->unsignedBigInteger('rkh_detail_id')->nullable()->after('id');
                $table->index('rkh_detail_id', 'ro_visits_rkh_detail_id_idx');
            }

            // 2) Unique: 1 rkh_detail hanya boleh punya 1 ro_visit
            // (Ini kunci biar "Isi LKH" tidak bikin dobel record)
            // NOTE: unique nullable di MySQL: boleh banyak NULL, tapi non-null harus unik → cocok!
            try {
                $table->unique('rkh_detail_id', 'ro_visits_rkh_detail_id_uniq');
            } catch (\Throwable $e) {
                // ignore jika sudah ada
            }
        });

        // 3) OPTIONAL: foreign key ke rkh_details (kalau rkh_details memang ada)
        // Banyak project internal kadang gak pakai FK demi fleksibilitas,
        // tapi kalau kamu mau strict: aktifkan FK ini.
        // Aku bikin aman: cek dulu tabelnya ada.
        if (Schema::hasTable('rkh_details')) {
            // karena Blueprint::foreign() kadang error kalau constraint sudah ada,
            // kita pakai raw SQL yang aman.
            try {
                DB::statement("
                    ALTER TABLE ro_visits
                    ADD CONSTRAINT ro_visits_rkh_detail_fk
                    FOREIGN KEY (rkh_detail_id) REFERENCES rkh_details(id)
                    ON DELETE SET NULL
                ");
            } catch (\Throwable $e) {
                // ignore kalau sudah ada / engine tidak support / dll
            }
        }

        /**
         * 4) OPTIONAL HARDENING (SAFE):
         * user_id sekarang nullable.
         * Kalau kamu ingin rapihin: jadikan NOT NULL, tapi hanya jika data sudah clean.
         */
        try {
            $nullCount = (int) DB::table('ro_visits')->whereNull('user_id')->count();
            if ($nullCount === 0) {
                // user_id jadi NOT NULL
                // NOTE: ini raw karena Laravel modify column butuh doctrine/dbal
                DB::statement("ALTER TABLE ro_visits MODIFY user_id BIGINT(20) UNSIGNED NOT NULL");
            }
        } catch (\Throwable $e) {
            // ignore bila tidak bisa modify kolom
        }
    }

    public function down(): void
    {
        // drop FK kalau ada
        try {
            DB::statement("ALTER TABLE ro_visits DROP FOREIGN KEY ro_visits_rkh_detail_fk");
        } catch (\Throwable $e) {
            // ignore
        }

        Schema::table('ro_visits', function (Blueprint $table) {
            try { $table->dropUnique('ro_visits_rkh_detail_id_uniq'); } catch (\Throwable $e) {}
            try { $table->dropIndex('ro_visits_rkh_detail_id_idx'); } catch (\Throwable $e) {}

            if (Schema::hasColumn('ro_visits', 'rkh_detail_id')) {
                $table->dropColumn('rkh_detail_id');
            }
        });
    }
};
