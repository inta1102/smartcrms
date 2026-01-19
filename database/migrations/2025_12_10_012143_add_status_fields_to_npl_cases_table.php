<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('npl_cases', function (Blueprint $table) {
            // ❌ TIDAK USAH tambah 'status' lagi, sudah ada

            // ✅ Tambah field pendukung penutupan kasus
            if (!Schema::hasColumn('npl_cases', 'closed_reason')) {
                $table->string('closed_reason')->nullable()->after('closed_at');
            }

            if (!Schema::hasColumn('npl_cases', 'closed_by')) {
                $table->string('closed_by')->nullable()->after('closed_reason');
            }

            if (!Schema::hasColumn('npl_cases', 'reopened_at')) {
                $table->date('reopened_at')->nullable()->after('closed_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('npl_cases', function (Blueprint $table) {
            if (Schema::hasColumn('npl_cases', 'closed_reason')) {
                $table->dropColumn('closed_reason');
            }
            if (Schema::hasColumn('npl_cases', 'closed_by')) {
                $table->dropColumn('closed_by');
            }
            if (Schema::hasColumn('npl_cases', 'reopened_at')) {
                $table->dropColumn('reopened_at');
            }
        });
    }
};
