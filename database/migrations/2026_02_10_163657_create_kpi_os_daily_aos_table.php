<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_os_daily_aos', function (Blueprint $table) {
            $table->id();

            $table->date('position_date');              // YYYY-MM-DD
            $table->string('ao_code', 6);               // normalized 6 digit
            $table->unsignedBigInteger('os_total')->default(0);
            $table->unsignedInteger('noa_total')->default(0);

            $table->string('source', 20)->default('loan_accounts'); // optional
            $table->timestamp('computed_at')->nullable();

            $table->timestamps();

            $table->unique(['position_date', 'ao_code'], 'uq_kpi_os_daily_aos_date_ao');
            $table->index(['ao_code', 'position_date'], 'ix_kpi_os_daily_aos_ao_date');
            $table->index(['position_date'], 'ix_kpi_os_daily_aos_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_os_daily_aos');
    }
};
