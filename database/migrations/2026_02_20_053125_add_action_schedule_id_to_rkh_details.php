<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rkh_details', function (Blueprint $table) {
            if (!Schema::hasColumn('rkh_details', 'action_schedule_id')) {
                $table->unsignedBigInteger('action_schedule_id')->nullable()->after('visit_schedule_id');
                $table->index('action_schedule_id', 'rkh_details_idx_action_schedule');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rkh_details', function (Blueprint $table) {
            if (Schema::hasColumn('rkh_details', 'action_schedule_id')) {
                $table->dropIndex('rkh_details_idx_action_schedule');
                $table->dropColumn('action_schedule_id');
            }
        });
    }
};
