<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('npl_cases', function (Blueprint $table) {
            $table->longText('assessment')->nullable()->after('summary');
            $table->unsignedBigInteger('assessment_updated_by')->nullable()->after('assessment');
            $table->timestamp('assessment_updated_at')->nullable()->after('assessment_updated_by');

            $table->index('assessment_updated_by');
        });
    }

    public function down(): void
    {
        Schema::table('npl_cases', function (Blueprint $table) {
            $table->dropIndex(['assessment_updated_by']);
            $table->dropColumn(['assessment', 'assessment_updated_by', 'assessment_updated_at']);
        });
    }
};
