<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kpi_be_targets', function (Blueprint $table) {
            // ✅ Target 3 metric BE (NEW)
            // 1) Recovery principal (Rp)
            if (!Schema::hasColumn('kpi_be_targets', 'target_recovery')) {
                $table->decimal('target_recovery', 18, 2)
                    ->default(0)
                    ->after('target_denda_masuk');
            }

            // 2) Lunas rate (%) => 0..100
            if (!Schema::hasColumn('kpi_be_targets', 'target_lunas_rate')) {
                $table->decimal('target_lunas_rate', 6, 2)
                    ->default(0)
                    ->after('target_recovery');
            }

            // 3) Risk exit rate (%) => batas maksimum WO+AYDA / total exit
            if (!Schema::hasColumn('kpi_be_targets', 'target_risk_exit_rate')) {
                $table->decimal('target_risk_exit_rate', 6, 2)
                    ->default(0)
                    ->after('target_lunas_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('kpi_be_targets', function (Blueprint $table) {
            if (Schema::hasColumn('kpi_be_targets', 'target_risk_exit_rate')) {
                $table->dropColumn('target_risk_exit_rate');
            }
            if (Schema::hasColumn('kpi_be_targets', 'target_lunas_rate')) {
                $table->dropColumn('target_lunas_rate');
            }
            if (Schema::hasColumn('kpi_be_targets', 'target_recovery')) {
                $table->dropColumn('target_recovery');
            }
        });
    }
};