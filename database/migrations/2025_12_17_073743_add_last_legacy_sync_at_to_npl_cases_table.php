<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('npl_cases', function (Blueprint $table) {
            $table->timestamp('last_legacy_sync_at')
                  ->nullable()
                  ->after('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('npl_cases', function (Blueprint $table) {
            $table->dropColumn('last_legacy_sync_at');
        });
    }
};
