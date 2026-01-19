<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            $table->decimal('nilai_agunan_yg_diperhitungkan', 18, 2)
                ->nullable()
                ->after('outstanding') // sesuaikan jika kolom ini ada
                ->comment('Nilai agunan yang diperhitungkan sesuai ketentuan bank (eligible value)');
        });
    }

    public function down(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            $table->dropColumn('nilai_agunan_yg_diperhitungkan');
        });
    }
};
