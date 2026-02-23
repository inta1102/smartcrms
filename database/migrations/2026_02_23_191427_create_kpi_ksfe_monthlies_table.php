<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_ksfe_monthlies', function (Blueprint $table) {
            $table->id();

            $table->date('period')->index();

            // Leader KSFE
            $table->unsignedBigInteger('ksfe_id')->index();

            // Mode mengikuti TLFE/FE (realtime/eom)
            $table->enum('calc_mode', ['realtime', 'eom'])->default('eom')->index();

            // Scope TLFE count (penting untuk kasus baru 1 TLFE)
            $table->unsignedInteger('tlfe_count')->default(0);

            // Leadership components
            $table->decimal('pi_scope', 6, 2)->default(0);          // avg LI TLFE (atau avg PI_scope TLFE sesuai desain)
            $table->decimal('stability_index', 6, 2)->nullable();   // antar TLFE; jika tlfe_count < 2 bisa netral / null
            $table->decimal('risk_index', 6, 2)->nullable();        // agregat risk governance
            $table->decimal('improvement_index', 6, 2)->nullable(); // MoM delta

            $table->decimal('leadership_index', 6, 2)->default(0);

            $table->string('status_label', 24)->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['period', 'ksfe_id', 'calc_mode'], 'uq_kpi_ksfe_period_leader_mode');

            // Optional FK
            // $table->foreign('ksfe_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_ksfe_monthlies');
    }
};