<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            // OWNER (stabil)
            if (!Schema::hasColumn('loan_accounts', 'owner_ao_code')) {
                $table->string('owner_ao_code', 20)->nullable()->after('ao_code');
                $table->string('owner_ao_name', 150)->nullable()->after('owner_ao_code');
                $table->index('owner_ao_code');
            }

            // PIC (dinamis)
            if (!Schema::hasColumn('loan_accounts', 'collector_code')) {
                $table->string('collector_code', 20)->nullable()->after('owner_ao_name');
                $table->string('collector_name', 150)->nullable()->after('collector_code');
                $table->index('collector_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('loan_accounts', 'collector_code')) {
                $table->dropIndex(['collector_code']);
                $table->dropColumn(['collector_code', 'collector_name']);
            }
            if (Schema::hasColumn('loan_accounts', 'owner_ao_code')) {
                $table->dropIndex(['owner_ao_code']);
                $table->dropColumn(['owner_ao_code', 'owner_ao_name']);
            }
        });
    }
};
