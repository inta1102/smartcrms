<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Pastikan kolom active_key ada
        Schema::table('org_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('org_assignments', 'active_key')) {
                $table->string('active_key', 255)
                    ->nullable()
                    ->after('is_active')
                    ->comment('Unique key hanya untuk assignment aktif (effective_to NULL & is_active=1)');
            }
        });

        // 2) Normalisasi dulu: set NULL untuk semua (biar bersih)
        DB::statement("UPDATE org_assignments SET active_key = NULL");

        // 3) Bersihkan duplikasi record AKTIF:
        // definisi aktif: is_active=1 AND effective_to IS NULL
        //
        // Strategi: untuk setiap (user_id, unit_code) pilih 1 record aktif terbaru,
        // sisanya dinonaktifkan & effective_to diisi hari ini (atau sehari sebelum hari ini).
        //
        // Catatan: ini tidak menghapus data, hanya menonaktifkan duplikat.
        DB::statement("
            UPDATE org_assignments oa
            JOIN (
                SELECT user_id,
                       IFNULL(unit_code,'') AS unit_key,
                       MAX(id) AS keep_id
                FROM org_assignments
                WHERE is_active = 1 AND effective_to IS NULL
                GROUP BY user_id, IFNULL(unit_code,'')
                HAVING COUNT(*) > 1
            ) d
              ON d.user_id = oa.user_id
             AND d.unit_key = IFNULL(oa.unit_code,'')
            SET oa.is_active = CASE WHEN oa.id = d.keep_id THEN 1 ELSE 0 END,
                oa.effective_to = CASE WHEN oa.id = d.keep_id THEN NULL ELSE CURDATE() END
            WHERE oa.is_active = 1 AND oa.effective_to IS NULL
        ");

        // 4) Backfill active_key hanya untuk yang masih aktif
        DB::statement("
            UPDATE org_assignments
            SET active_key = CONCAT(
                user_id,'|',leader_id,'|',IFNULL(unit_code,''),'|',IFNULL(leader_role,'')
            )
            WHERE is_active = 1 AND effective_to IS NULL
        ");

        // 5) Pasang unique index (kalau belum ada)
        // Kalau sudah ada index dengan nama berbeda, nanti kita rapikan manual pakai SHOW INDEX.
        Schema::table('org_assignments', function (Blueprint $table) {
            // coba create langsung; kalau sudah ada, DB akan error.
            // jadi kita guard pakai try-catch via statement? tidak bisa di migration.
            // Solusi: cek lewat information_schema
        });

        $exists = DB::selectOne("
            SELECT COUNT(1) AS cnt
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'org_assignments'
              AND index_name = 'uq_org_assignments_active_key'
        ");

        if ((int)($exists->cnt ?? 0) === 0) {
            DB::statement("ALTER TABLE org_assignments ADD UNIQUE uq_org_assignments_active_key (active_key)");
        }
    }

    public function down(): void
    {
        // drop unique jika ada
        $exists = DB::selectOne("
            SELECT COUNT(1) AS cnt
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'org_assignments'
              AND index_name = 'uq_org_assignments_active_key'
        ");

        if ((int)($exists->cnt ?? 0) > 0) {
            DB::statement("ALTER TABLE org_assignments DROP INDEX uq_org_assignments_active_key");
        }

        // jangan drop kolom kalau sudah dipakai production; tapi kalau kamu mau bersih:
        // Schema::table('org_assignments', fn(Blueprint $t) => $t->dropColumn('active_key'));
    }
};
