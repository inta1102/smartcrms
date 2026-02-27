<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_ro_topup_adj_lines', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('batch_id');

            // Bulan KPI (duplikat dari header untuk memudahkan query YTD)
            $table->date('period_month');

            $table->string('cif', 32);

            // AO asal (default dari transaksi terbesar bulan itu)
            $table->string('source_ao_code', 6)->nullable();

            // AO tujuan (yang berhak menikmati kredit)
            $table->string('target_ao_code', 6);

            // Nilai yang dibekukan saat approve
            $table->decimal('amount_frozen', 18, 2)->default(0);

            // Cutoff saat perhitungan freeze
            $table->date('calc_as_of_date')->nullable();

            // Simpan meta perhitungan (audit trail)
            $table->json('calc_meta')->nullable();

            $table->text('reason')->nullable();

            $table->timestamps();

            // FK
            $table->foreign('batch_id')
                  ->references('id')
                  ->on('kpi_ro_topup_adj_batches')
                  ->onDelete('cascade');

            // Index penting untuk performa YTD
            $table->index(['period_month','cif']);
            $table->index(['period_month','target_ao_code']);
            $table->index(['period_month','source_ao_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_ro_topup_adj_lines');
    }
};