<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('legal_action_ht_auctions', function (Blueprint $table) {
            if (Schema::hasColumn('legal_action_ht_auctions', 'minutes_file_path')) {
                $table->dropColumn('minutes_file_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('legal_action_ht_auctions', function (Blueprint $table) {
            if (!Schema::hasColumn('legal_action_ht_auctions', 'minutes_file_path')) {
                $table->string('minutes_file_path', 500)->nullable()->after('settlement_date');
            }
        });
    }
};
