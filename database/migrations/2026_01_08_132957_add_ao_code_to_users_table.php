<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ao_code: kode AO seperti di loan_accounts.ao_code (mis: 000018)
            $table->string('ao_code', 20)
                ->nullable()
                ->after('employee_code')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['ao_code']);
            $table->dropColumn('ao_code');
        });
    }
};
