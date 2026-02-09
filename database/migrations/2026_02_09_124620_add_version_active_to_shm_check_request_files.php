<?php

// database/migrations/2026_02_09_xxxxxx_add_version_active_to_shm_check_request_files.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shm_check_request_files', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('type');
            $table->boolean('is_active')->default(true)->after('version');
            $table->index(['request_id', 'type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('shm_check_request_files', function (Blueprint $table) {
            $table->dropIndex(['request_id', 'type', 'is_active']);
            $table->dropColumn(['version', 'is_active']);
        });
    }
};
