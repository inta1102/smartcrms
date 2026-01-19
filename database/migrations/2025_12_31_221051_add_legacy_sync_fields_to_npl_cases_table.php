<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('npl_cases', function (Blueprint $table) {
            if (!Schema::hasColumn('npl_cases', 'last_legacy_sync_at')) {
                $table->timestamp('last_legacy_sync_at')->nullable()->after('updated_at');
            }
            if (!Schema::hasColumn('npl_cases', 'legacy_sp_fingerprint')) {
                $table->string('legacy_sp_fingerprint', 64)->nullable()->after('last_legacy_sync_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('npl_cases', function (Blueprint $table) {
            if (Schema::hasColumn('npl_cases', 'last_legacy_sync_at')) {
                $table->dropColumn('last_legacy_sync_at');
            }
            if (Schema::hasColumn('npl_cases', 'legacy_sp_fingerprint')) {
                $table->dropColumn('legacy_sp_fingerprint');
            }
        });
    }
};
