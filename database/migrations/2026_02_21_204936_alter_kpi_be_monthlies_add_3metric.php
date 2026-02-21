<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kpi_be_monthlies', function (Blueprint $table) {

            // ======================
            // METRIC 1 - RECOVERY
            // ======================
            $table->decimal('recovery_principal', 18, 2)->default(0)->after('be_user_id');
            $table->decimal('target_recovery', 18, 2)->default(0)->after('recovery_principal');
            $table->decimal('recovery_pct', 6, 2)->default(0)->after('target_recovery');
            $table->unsignedTinyInteger('score_recovery')->default(1)->after('recovery_pct');

            // ======================
            // METRIC 2 - EXIT LUNAS
            // ======================
            $table->unsignedInteger('lunas_count')->default(0)->after('score_recovery');
            $table->unsignedInteger('wo_count')->default(0)->after('lunas_count');
            $table->unsignedInteger('ayda_count')->default(0)->after('wo_count');
            $table->unsignedInteger('total_exit')->default(0)->after('ayda_count');

            $table->decimal('lunas_rate', 6, 2)->default(0)->after('total_exit');
            $table->unsignedTinyInteger('score_lunas')->default(1)->after('lunas_rate');

            // ======================
            // METRIC 3 - RISK EXIT
            // ======================
            $table->decimal('risk_exit_rate', 6, 2)->default(0)->after('score_lunas');
            $table->unsignedTinyInteger('score_risk')->default(1)->after('risk_exit_rate');

            // ======================
            // FINAL
            // ======================
            $table->decimal('final_score', 6, 2)->default(0)->after('score_risk');
            $table->string('grade', 10)->nullable()->after('final_score');

            // Index penting
            $table->index(['period', 'be_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('kpi_be_monthlies', function (Blueprint $table) {

            $table->dropColumn([
                'recovery_principal',
                'target_recovery',
                'recovery_pct',
                'score_recovery',

                'lunas_count',
                'wo_count',
                'ayda_count',
                'total_exit',
                'lunas_rate',
                'score_lunas',

                'risk_exit_rate',
                'score_risk',

                'final_score',
                'grade',
            ]);
        });
    }
};
