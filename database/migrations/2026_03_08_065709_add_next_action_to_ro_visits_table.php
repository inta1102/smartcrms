<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ro_visits', function (Blueprint $table) {
            if (!Schema::hasColumn('ro_visits', 'next_action')) {
                $table->text('next_action')->nullable()->after('lkh_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ro_visits', function (Blueprint $table) {
            if (Schema::hasColumn('ro_visits', 'next_action')) {
                $table->dropColumn('next_action');
            }
        });
    }
};