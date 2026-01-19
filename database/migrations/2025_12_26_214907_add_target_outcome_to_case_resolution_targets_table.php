<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom target_outcome (lunas / lancar)
     */
    public function up(): void
    {
        Schema::table('case_resolution_targets', function (Blueprint $table) {
            $table->string('target_outcome', 20)
                ->nullable()
                ->after('strategy')
                ->comment('Target kondisi penyelesaian: lunas | lancar');
        });
    }

    /**
     * Rollback
     */
    public function down(): void
    {
        Schema::table('case_resolution_targets', function (Blueprint $table) {
            $table->dropColumn('target_outcome');
        });
    }
};
