<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_account_snapshots_monthly', function (Blueprint $table) {
            // frekuensi tunggakan pokok & bunga
            $table->unsignedSmallInteger('ft_pokok')->default(0)->after('dpd');
            $table->unsignedSmallInteger('ft_bunga')->default(0)->after('ft_pokok');

            // kalau mau cepat buat query, bisa index kecil (opsional)
            // $table->index(['snapshot_month', 'ao_code'], 'idx_snap_month_ao');
        });
    }

    public function down(): void
    {
        Schema::table('loan_account_snapshots_monthly', function (Blueprint $table) {
            $table->dropIndex('idx_snap_month_ao');
            $table->dropColumn(['ft_pokok', 'ft_bunga']);
        });
    }
};
