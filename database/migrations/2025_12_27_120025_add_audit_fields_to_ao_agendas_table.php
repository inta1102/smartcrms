<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {

            // catatan umum agenda
            if (!Schema::hasColumn('ao_agendas', 'notes')) {
                $table->text('notes')->nullable()->after('title');
            }

            // tracking eksekusi
            if (!Schema::hasColumn('ao_agendas', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('planned_at');
            }
            if (!Schema::hasColumn('ao_agendas', 'started_by')) {
                $table->unsignedBigInteger('started_by')->nullable()->after('started_at');
            }

            // IMPORTANT: di DB kamu completed_at sudah datetime.
            // Jadi kalau belum ada baru kita tambahkan sebagai datetime agar konsisten.
            if (!Schema::hasColumn('ao_agendas', 'completed_at')) {
                $table->dateTime('completed_at')->nullable()->after('due_at');
            }
            if (!Schema::hasColumn('ao_agendas', 'completed_by')) {
                $table->unsignedBigInteger('completed_by')->nullable()->after('completed_at');
            }

            // hasil & evidence
            if (!Schema::hasColumn('ao_agendas', 'result_detail')) {
                $table->text('result_detail')->nullable()->after('result_summary');
            }
            if (!Schema::hasColumn('ao_agendas', 'evidence_path')) {
                $table->string('evidence_path', 500)->nullable()->after('evidence_required');
            }
            if (!Schema::hasColumn('ao_agendas', 'evidence_notes')) {
                $table->string('evidence_notes', 500)->nullable()->after('evidence_path');
            }

            // audit by user
            if (!Schema::hasColumn('ao_agendas', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('evidence_notes');
            }
            if (!Schema::hasColumn('ao_agendas', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            }

            // soft delete
            if (!Schema::hasColumn('ao_agendas', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {

            // drop kolom hanya jika ada (biar aman)
            foreach ([
                'notes',
                'started_at', 'started_by',
                'completed_at', 'completed_by',
                'result_detail',
                'evidence_path', 'evidence_notes',
                'created_by', 'updated_by',
                'deleted_at',
            ] as $col) {
                if (Schema::hasColumn('ao_agendas', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
