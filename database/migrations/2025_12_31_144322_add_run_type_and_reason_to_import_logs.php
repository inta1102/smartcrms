<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {

            // tipe eksekusi: import pertama / re-import koreksi
            if (!Schema::hasColumn('import_logs', 'run_type')) {
                $table->enum('run_type', ['import', 'reimport'])
                      ->default('import')
                      ->after('position_date')
                      ->index();
            }

            // alasan re-import (wajib di level aplikasi)
            if (!Schema::hasColumn('import_logs', 'reason')) {
                $table->text('reason')
                      ->nullable()
                      ->after('message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            if (Schema::hasColumn('import_logs', 'run_type')) {
                $table->dropColumn('run_type');
            }

            if (Schema::hasColumn('import_logs', 'reason')) {
                $table->dropColumn('reason');
            }
        });
    }
};
