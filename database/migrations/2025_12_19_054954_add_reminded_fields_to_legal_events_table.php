<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('legal_events', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('remind_at');
            $table->foreignId('reminded_by')->nullable()->after('reminded_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['status', 'remind_at', 'reminded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('legal_events', function (Blueprint $table) {
            $table->dropIndex(['status', 'remind_at', 'reminded_at']);
            $table->dropConstrainedForeignId('reminded_by');
            $table->dropColumn('reminded_at');
        });
    }
};

