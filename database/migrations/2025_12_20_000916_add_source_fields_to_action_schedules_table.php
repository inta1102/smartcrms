<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('action_schedules', function (Blueprint $table) {
            // asal schedule: 'sp','visit','non_litigation','legal', dst
            $table->string('source_system', 50)->nullable()->after('npl_case_id');
            $table->unsignedBigInteger('source_ref_id')->nullable()->after('source_system');

            // optional tapi penting untuk performa dan cegah duplikasi
            $table->index(['npl_case_id','source_system','source_ref_id','status'], 'idx_sched_source_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('action_schedules', function (Blueprint $table) {
            $table->dropIndex('idx_sched_source_lookup');
            $table->dropColumn(['source_system','source_ref_id']);
        });
    }
};
