<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('action_schedules', function (Blueprint $table) {
            // morph columns
            $table->string('schedulable_type', 191)->nullable()->after('npl_case_id');
            $table->unsignedBigInteger('schedulable_id')->nullable()->after('schedulable_type');

            $table->index(['schedulable_type', 'schedulable_id'], 'idx_schedulable');
            $table->index(['npl_case_id', 'status', 'scheduled_at'], 'idx_sched_main');
        });

        /**
         * Migrasi data lama (source_system/source_ref_id) -> schedulable_type/schedulable_id
         * Mapping: sesuaikan dengan nama class model kamu.
         */
        $map = [
            'non_litigation' => \App\Models\NonLitigationAction::class,
            'legal'          => \App\Models\LegalAction::class,
            // contoh lain kalau ada:
            // 'sp'          => \App\Models\CaseAction::class,
            // 'visit'       => \App\Models\Visit::class,
        ];

        foreach ($map as $source => $class) {
            DB::table('action_schedules')
                ->where('source_system', $source)
                ->whereNotNull('source_ref_id')
                ->update([
                    'schedulable_type' => $class,
                    'schedulable_id'   => DB::raw('source_ref_id'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('action_schedules', function (Blueprint $table) {
            $table->dropIndex('idx_schedulable');
            $table->dropIndex('idx_sched_main');
            $table->dropColumn(['schedulable_type','schedulable_id']);
        });
    }
};
