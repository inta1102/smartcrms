<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_actions', function (Blueprint $table) {

            // === Audit Close Information ===
            $table->timestamp('closed_at')
                ->nullable()
                ->after('status');

            $table->unsignedBigInteger('closed_by')
                ->nullable()
                ->after('closed_at');

            $table->text('closed_notes')
                ->nullable()
                ->after('closed_by');

            // === Index untuk audit & reporting ===
            $table->index('closed_at');
            $table->index('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('legal_actions', function (Blueprint $table) {

            // drop index dulu
            $table->dropIndex(['closed_at']);
            $table->dropIndex(['closed_by']);

            // drop kolom
            $table->dropColumn([
                'closed_at',
                'closed_by',
                'closed_notes',
            ]);
        });
    }
};
