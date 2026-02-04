<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            // frekuensi tunggakan pokok & bunga (0 = lancar)
            $table->unsignedSmallInteger('ft_pokok')->default(0)->after('dpd');
            $table->unsignedSmallInteger('ft_bunga')->default(0)->after('ft_pokok');

            // optional tapi bagus untuk query KPI cepat
            $table->index(['position_date', 'ao_code']);
            $table->index(['position_date', 'ft_pokok', 'ft_bunga']);
        });
    }

    public function down(): void
    {
        Schema::table('loan_accounts', function (Blueprint $table) {
            $table->dropIndex(['loan_accounts_position_date_ao_code_index']);
            $table->dropIndex(['loan_accounts_position_date_ft_pokok_ft_bunga_index']);

            $table->dropColumn(['ft_pokok','ft_bunga']);
        });
    }
};
