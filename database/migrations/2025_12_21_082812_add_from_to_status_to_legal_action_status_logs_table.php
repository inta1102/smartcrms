<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_action_status_logs', function (Blueprint $table) {

            // kolom from_status
            if (!Schema::hasColumn('legal_action_status_logs', 'from_status')) {
                $table->string('from_status', 50)->nullable()->after('legal_action_id');
            }

            // kolom to_status
            if (!Schema::hasColumn('legal_action_status_logs', 'to_status')) {
                $table->string('to_status', 50)->nullable()->after('from_status');
            }
        });

        // ✅ index (legal_action_id, changed_at) - hanya kalau belum ada
        $indexName = 'legal_action_status_logs_legal_action_id_changed_at_index';

        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'legal_action_status_logs')
            ->where('index_name', $indexName)
            ->exists();

        if (!$exists) {
            Schema::table('legal_action_status_logs', function (Blueprint $table) use ($indexName) {
                $table->index(['legal_action_id', 'changed_at'], $indexName);
            });
        }
    }

    public function down(): void
    {
        // drop index jika ada
        $indexName = 'legal_action_status_logs_legal_action_id_changed_at_index';

        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', 'legal_action_status_logs')
            ->where('index_name', $indexName)
            ->exists();

        if ($exists) {
            Schema::table('legal_action_status_logs', function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }

        Schema::table('legal_action_status_logs', function (Blueprint $table) {
            if (Schema::hasColumn('legal_action_status_logs', 'to_status')) {
                $table->dropColumn('to_status');
            }
            if (Schema::hasColumn('legal_action_status_logs', 'from_status')) {
                $table->dropColumn('from_status');
            }
        });
    }
};
