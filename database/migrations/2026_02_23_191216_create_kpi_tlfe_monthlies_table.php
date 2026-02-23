<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_tlfe_monthlies', function (Blueprint $table) {
            $table->id();

            // Periode KPI (umumnya pakai tanggal 1 pada bulan tsb)
            $table->date('period')->index();

            // Leader TLFE
            $table->unsignedBigInteger('tlfe_id')->index();

            // Mode mengikuti FE: realtime (bulan berjalan) / eom (freeze)
            $table->enum('calc_mode', ['realtime', 'eom'])->default('eom')->index();

            // Scope info
            $table->unsignedInteger('fe_count')->default(0);

            // Leadership components (skala 1..6; PI_scope bisa decimal)
            $table->decimal('pi_scope', 6, 2)->default(0);          // avg PI FE
            $table->decimal('stability_index', 6, 2)->nullable();   // gabungan spread/bottom/coverage
            $table->decimal('risk_index', 6, 2)->nullable();        // berbasis migrasi actual (ideal)
            $table->decimal('improvement_index', 6, 2)->nullable(); // MoM delta PI_scope

            // Final Leadership Index (skala 1..6)
            $table->decimal('leadership_index', 6, 2)->default(0);

            // Label status AI / rule-based
            $table->string('status_label', 24)->nullable(); // AMAN | WASPADA | KRITIS | dll

            // Meta untuk audit & AI engine (spread, bottom, coverage, avg_migrasi, top/bottom list ringkas)
            $table->json('meta')->nullable();

            $table->timestamps();

            // 1 record per TLFE per period per mode
            $table->unique(['period', 'tlfe_id', 'calc_mode'], 'uq_kpi_tlfe_period_leader_mode');

            // Optional FK (aktifkan kalau users table ada)
            // $table->foreign('tlfe_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_tlfe_monthlies');
    }
};