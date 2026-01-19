<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            // Restruktur
            $table->boolean('is_restructured')
                ->default(false)
                ->after('collector_code'); // sesuaikan posisi kolom existing
            $table->unsignedTinyInteger('restructure_freq')
                ->default(0)
                ->after('is_restructured');
            $table->date('last_restructure_date')
                ->nullable()
                ->after('restructure_freq');

            // Angsuran & pembayaran
            $table->unsignedTinyInteger('installment_day')
                ->nullable()
                ->comment('Tanggal jatuh tempo angsuran (1-31)')
                ->after('last_restructure_date');

            $table->date('last_payment_date')
                ->nullable()
                ->after('installment_day');
        });
    }

    public function down(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'is_restructured',
                'restructure_freq',
                'last_restructure_date',
                'installment_day',
                'last_payment_date',
            ]);
        });
    }
};
