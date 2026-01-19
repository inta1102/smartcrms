<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('case_resolution_targets', function (Blueprint $table) {

            if (!Schema::hasColumn('case_resolution_targets', 'reject_reason')) {
                $table->string('reject_reason', 500)->nullable()->after('rejected_at');
            }

            if (!Schema::hasColumn('case_resolution_targets', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('kasi_notes');
            }

            if (!Schema::hasColumn('case_resolution_targets', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }

        });
    }

    public function down(): void
    {
        Schema::table('case_resolution_targets', function (Blueprint $table) {

            if (Schema::hasColumn('case_resolution_targets', 'reject_reason')) {
                $table->dropColumn('reject_reason');
            }

            if (Schema::hasColumn('case_resolution_targets', 'approved_by')) {
                $table->dropColumn('approved_by');
            }

            if (Schema::hasColumn('case_resolution_targets', 'approved_at')) {
                $table->dropColumn('approved_at');
            }

        });
    }
};
