<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('npl_cases', function (Blueprint $table) {
            $table->boolean('is_legal')->default(false)->after('priority');
            $table->timestamp('legal_started_at')->nullable()->after('is_legal');
            $table->text('legal_note')->nullable()->after('legal_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('npl_cases', function (Blueprint $table) {
            $table->dropColumn(['is_legal', 'legal_started_at', 'legal_note']);
        });
    }
};
