<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('legal_action_proposals', function (Blueprint $table) {
            $table->text('executed_notes')
                ->nullable()
                ->after('executed_proof_size');
        });
    }

    public function down(): void
    {
        Schema::table('legal_action_proposals', function (Blueprint $table) {
            $table->dropColumn('executed_notes');
        });
    }
};
