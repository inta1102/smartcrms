<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_kbl_targets', function (Blueprint $table) {
            $table->id();

            // Kabag Lending user id
            $table->unsignedBigInteger('kbl_id');

            // Periode KPI (pakai awal bulan: YYYY-MM-01)
            $table->date('period');

            // Target Kabag Lending (sesuai PPT)
            $table->decimal('target_os', 18, 2)->default(0);              // Target KYD (OS)
            $table->decimal('target_npl_pct', 6, 2)->default(0);          // Target NPL ratio (%) atau threshold
            $table->decimal('target_interest_income', 18, 2)->default(0); // Target pendapatan bunga (Rp)
            $table->unsignedInteger('target_community')->default(0);      // Target pemasaran komunitas (jumlah)

            // optional meta
            $table->longText('meta')->nullable();

            $table->timestamps();

            // unique per kabag + periode
            $table->unique(['kbl_id', 'period'], 'uk_kbl_targets_kbl_period');

            // index bantu query
            $table->index(['period'], 'idx_kbl_targets_period');
            $table->index(['kbl_id'], 'idx_kbl_targets_kbl');

            // FK optional (kalau users tabel ada)
            $table->foreign('kbl_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_kbl_targets');
    }
};