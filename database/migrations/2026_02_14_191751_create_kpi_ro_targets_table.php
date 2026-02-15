<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_ro_targets', function (Blueprint $table) {
            $table->id();

            // YYYY-MM-01
            $table->date('period')->index();

            // pakai ao_code supaya konsisten dengan kpi_ro_monthly
            $table->string('ao_code', 20)->index();

            // target-target (boleh null, nanti fallback ke default)
            $table->decimal('target_topup', 18, 2)->nullable();   // default 750_000_000
            $table->unsignedInteger('target_noa')->nullable();    // default 2
            $table->decimal('target_rr_pct', 6, 2)->nullable();   // default 100.00 (kalau mau)
            $table->decimal('target_dpk_pct', 6, 2)->nullable();  // default 0.00 / atau null

            // audit
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['period', 'ao_code'], 'uq_kpi_ro_targets_period_ao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_ro_targets');
    }
};
