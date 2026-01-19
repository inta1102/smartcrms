<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_schedules', function (Blueprint $table) {
            // =========================
            // A) Kolom eskalasi & audit
            // =========================
            if (!Schema::hasColumn('action_schedules', 'level')) {
                $table->string('level', 30)->nullable()->after('type')
                    ->comment('Level rule: CONTACT/SP1/SP2/SP3/...');
            }

            if (!Schema::hasColumn('action_schedules', 'rule_version')) {
                $table->string('rule_version', 20)->nullable()->after('level')
                    ->comment('Versi aturan scheduler untuk audit');
            }

            if (!Schema::hasColumn('action_schedules', 'escalated_at')) {
                $table->dateTime('escalated_at')->nullable()->after('completed_at')
                    ->comment('Waktu jadwal ini dieskalasi (karena threshold naik)');
            }

            if (!Schema::hasColumn('action_schedules', 'escalation_note')) {
                $table->text('escalation_note')->nullable()->after('escalated_at')
                    ->comment('Catatan: jadwal sebelumnya belum follow up saat eskalasi');
            }

            // relasi antar schedule (self reference)
            if (!Schema::hasColumn('action_schedules', 'escalated_from_id')) {
                $table->unsignedBigInteger('escalated_from_id')->nullable()->after('escalation_note')
                    ->comment('Schedule sebelumnya yang belum follow up (asal eskalasi)');
            }

            if (!Schema::hasColumn('action_schedules', 'escalated_to_id')) {
                $table->unsignedBigInteger('escalated_to_id')->nullable()->after('escalated_from_id')
                    ->comment('Schedule baru hasil eskalasi dari schedule ini');
            }

            // index biar query cepat
            $table->index(['npl_case_id', 'status', 'scheduled_at'], 'idx_case_status_sched');
            $table->index(['type', 'status'], 'idx_type_status');
            $table->index(['level', 'status'], 'idx_level_status');

            // FK self reference (optional: kalau mau strict)
            // Note: MySQL butuh engine InnoDB & kolom unsignedBigInteger.
            $table->foreign('escalated_from_id', 'fk_sched_escalated_from')
                ->references('id')->on('action_schedules')
                ->nullOnDelete();

            $table->foreign('escalated_to_id', 'fk_sched_escalated_to')
                ->references('id')->on('action_schedules')
                ->nullOnDelete();
        });

        // =========================
        // B) Update ENUM status
        // =========================
        // Karena kolom status kamu ENUM('pending','done','cancelled')
        // kita perlu tambah: escalated, skipped
        // Paling aman pakai raw SQL.
        DB::statement("
            ALTER TABLE action_schedules
            MODIFY status ENUM('pending','done','cancelled','escalated','skipped')
            NOT NULL DEFAULT 'pending'
        ");
    }

    public function down(): void
    {
        // balikin ENUM status ke semula
        DB::statement("
            ALTER TABLE action_schedules
            MODIFY status ENUM('pending','done','cancelled')
            NOT NULL DEFAULT 'pending'
        ");

        Schema::table('action_schedules', function (Blueprint $table) {
            // drop FK dulu baru drop kolom
            if (Schema::hasColumn('action_schedules', 'escalated_from_id')) {
                $table->dropForeign('fk_sched_escalated_from');
            }
            if (Schema::hasColumn('action_schedules', 'escalated_to_id')) {
                $table->dropForeign('fk_sched_escalated_to');
            }

            // drop index
            $table->dropIndex('idx_case_status_sched');
            $table->dropIndex('idx_type_status');
            $table->dropIndex('idx_level_status');

            // drop columns
            $cols = [];
            foreach ([
                'level','rule_version','escalated_at','escalation_note',
                'escalated_from_id','escalated_to_id',
            ] as $c) {
                if (Schema::hasColumn('action_schedules', $c)) $cols[] = $c;
            }
            if (!empty($cols)) $table->dropColumn($cols);
        });
    }
};
