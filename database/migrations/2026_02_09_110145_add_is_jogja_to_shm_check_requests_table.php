<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shm_check_requests', function (Blueprint $table) {
            $table->boolean('is_jogja')->default(false)->after('collateral_address');
        });
    }

    public function down(): void
    {
        Schema::table('shm_check_requests', function (Blueprint $table) {
            $table->dropColumn('is_jogja');
        });
    }
};
