<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('legal_action_proposals', function (Blueprint $table) {
            // $table->date('planned_at')->nullable()->after('notes');      // rencana pasang plakat
            $table->string('executed_proof_path')->nullable()->after('executed_at'); // bukti
            $table->string('executed_proof_name')->nullable()->after('executed_proof_path');
            $table->string('executed_proof_mime')->nullable()->after('executed_proof_name');
            $table->unsignedBigInteger('executed_proof_size')->nullable()->after('executed_proof_mime');
        });
    }

    public function down(): void
    {
        Schema::table('legal_action_proposals', function (Blueprint $table) {
            $table->dropColumn([
                // 'planned_at',
                'executed_proof_path',
                'executed_proof_name',
                'executed_proof_mime',
                'executed_proof_size',
            ]);
        });
    }

};
