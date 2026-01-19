<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('action_schedules', function (Blueprint $table) {
            if (!Schema::hasColumn('action_schedules', 'source_system')) {
                $table->string('source_system', 50)->nullable()->after('source_ref_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('action_schedules', function (Blueprint $table) {
            if (Schema::hasColumn('action_schedules', 'source_system')) {
                $table->dropIndex(['source_system']);
                $table->dropColumn('source_system');
            }
        });
    }
};
