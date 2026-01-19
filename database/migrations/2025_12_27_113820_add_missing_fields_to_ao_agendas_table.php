<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {
            // ✅ kalau model AoAgenda pakai SoftDeletes, ini WAJIB
            if (!Schema::hasColumn('ao_agendas', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }

            // ✅ catatan/instruksi agenda (audit-friendly)
            if (!Schema::hasColumn('ao_agendas', 'notes')) {
                $table->text('notes')->nullable()->after('title');
            }

            // ✅ penutupan agenda
            if (!Schema::hasColumn('ao_agendas', 'completed_at')) {
                $table->dateTime('completed_at')->nullable()->after('due_at');
            }
            if (!Schema::hasColumn('ao_agendas', 'completed_by')) {
                $table->unsignedBigInteger('completed_by')->nullable()->after('completed_at');
                $table->index('completed_by');
            }

            // ✅ detail hasil (opsional)
            if (!Schema::hasColumn('ao_agendas', 'result_detail')) {
                $table->text('result_detail')->nullable()->after('result_summary');
            }

            // ✅ bukti (opsional)
            if (!Schema::hasColumn('ao_agendas', 'evidence_path')) {
                $table->string('evidence_path', 500)->nullable()->after('evidence_required');
            }
            if (!Schema::hasColumn('ao_agendas', 'evidence_notes')) {
                $table->string('evidence_notes', 500)->nullable()->after('evidence_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {
            if (Schema::hasColumn('ao_agendas', 'evidence_notes')) $table->dropColumn('evidence_notes');
            if (Schema::hasColumn('ao_agendas', 'evidence_path')) $table->dropColumn('evidence_path');
            if (Schema::hasColumn('ao_agendas', 'result_detail')) $table->dropColumn('result_detail');
            if (Schema::hasColumn('ao_agendas', 'completed_by')) {
                $table->dropIndex(['completed_by']);
                $table->dropColumn('completed_by');
            }
            if (Schema::hasColumn('ao_agendas', 'completed_at')) $table->dropColumn('completed_at');
            if (Schema::hasColumn('ao_agendas', 'notes')) $table->dropColumn('notes');
            if (Schema::hasColumn('ao_agendas', 'deleted_at')) $table->dropSoftDeletes();
        });
    }
};
