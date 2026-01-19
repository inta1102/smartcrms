<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('legal_action_ht_auctions', function (Blueprint $table) {
            $table->string('risalah_file_path')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('legal_action_ht_auctions', function (Blueprint $table) {
            $table->dropColumn('risalah_file_path');
        });
    }
};
