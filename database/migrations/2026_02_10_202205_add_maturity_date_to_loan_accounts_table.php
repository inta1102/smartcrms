<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            $table->date('maturity_date')->nullable()->after('tgl_kolek'); // sesuaikan after column
            $table->index('maturity_date');
        });
    }

    public function down(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            $table->dropIndex(['maturity_date']);
            $table->dropColumn('maturity_date');
        });
    }
};
