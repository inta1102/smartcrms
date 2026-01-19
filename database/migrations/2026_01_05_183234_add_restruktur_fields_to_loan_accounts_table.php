<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            // Flag restruktur
            $table->boolean('is_restructured')->default(false)->after('position_date');

            // Request user
            $table->date('tglakhir_restruktur')->nullable()->after('is_restructured');
            $table->unsignedSmallInteger('frek_restruktur')->default(0)->after('tglakhir_restruktur');

            // Recommended: biar monitoring 3 bulan gampang
            $table->date('monitor_restruktur_until')->nullable()->after('frek_restruktur');

            // Jika belum ada field jatuh tempo bayar berikutnya, aktifkan ini:
            // $table->date('next_installment_due')->nullable()->after('monitor_restruktur_until');

            // Tracking WA supaya tidak spam (idempotent)
            $table->timestamp('last_restruktur_wa_sent_at')->nullable()->after('monitor_restruktur_until');
        });
    }

    public function down(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'is_restructured',
                'tglakhir_restruktur',
                'frek_restruktur',
                'monitor_restruktur_until',
                'last_restruktur_wa_sent_at',
                // 'next_installment_due',
            ]);
        });
    }
};
