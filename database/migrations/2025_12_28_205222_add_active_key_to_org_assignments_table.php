<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_assignments', function (Blueprint $table) {
            // active_key dipakai hanya untuk record aktif (effective_to NULL & is_active=1)
            $table->string('active_key', 255)->nullable()->after('is_active');

            // Unique: mencegah record aktif dobel
            $table->unique('active_key', 'uq_org_assignments_active_key');
        });

        // Backfill: isi active_key untuk record aktif yang existing
        DB::statement("
            UPDATE org_assignments
            SET active_key = CONCAT(
                user_id,'|',leader_id,'|',IFNULL(unit_code,''),'|',IFNULL(leader_role,'')
            )
            WHERE is_active = 1 AND effective_to IS NULL
        ");

        // Record non-aktif (history) biarkan NULL supaya tidak kena unique
    }

    public function down(): void
    {
        Schema::table('org_assignments', function (Blueprint $table) {
            $table->dropUnique('uq_org_assignments_active_key');
            $table->dropColumn('active_key');
        });
    }
};
