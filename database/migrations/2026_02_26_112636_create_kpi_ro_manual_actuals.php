<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_ro_manual_actuals', function (Blueprint $table) {
            $table->id();
            $table->date('period');              // startOfMonth (YYYY-MM-01)
            $table->string('ao_code', 6);        // RO ao_code (pad 6)
            $table->unsignedInteger('noa_pengembangan')->default(0);
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('input_by')->nullable();
            $table->timestamp('input_at')->nullable();

            $table->timestamps();

            $table->unique(['period', 'ao_code'], 'uniq_kpi_ro_manual_period_ao');
            $table->index(['ao_code', 'period'], 'idx_kpi_ro_manual_ao_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_ro_manual_actuals');
    }
};