<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_tlro_monthlies', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tlro_id');
            $table->date('period');              // YYYY-mm-01
            $table->string('calc_mode', 20);     // realtime / eom

            $table->unsignedInteger('ro_count')->default(0);

            $table->decimal('pi_scope', 6,2)->default(0);
            $table->decimal('stability_index', 6,2)->default(0);
            $table->decimal('risk_index', 6,2)->default(0);
            $table->decimal('improvement_index', 6,2)->default(0);

            $table->decimal('leadership_index', 6,2)->default(0);
            $table->string('status_label', 20)->nullable();

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->unique(['tlro_id','period','calc_mode'], 'uniq_tlro_period_mode');
            $table->index(['period']);
            $table->index(['tlro_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_tlro_monthlies');
    }
};