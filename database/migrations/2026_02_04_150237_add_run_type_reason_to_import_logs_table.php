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
        Schema::table('import_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('import_logs', 'run_type')) {
                $table->string('run_type', 20)->nullable()->after('position_date');
            }
            if (!Schema::hasColumn('import_logs', 'reason')) {
                $table->text('reason')->nullable()->after('message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            if (Schema::hasColumn('import_logs', 'run_type')) $table->dropColumn('run_type');
            if (Schema::hasColumn('import_logs', 'reason')) $table->dropColumn('reason');
        });
    }

};
