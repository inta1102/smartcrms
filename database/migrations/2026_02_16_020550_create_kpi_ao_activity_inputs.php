<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_ao_activity_inputs', function (Blueprint $table) {
            $table->id();
            $table->date('period'); // Y-m-01
            $table->unsignedBigInteger('user_id');

            $table->integer('community_actual')->default(0);     // Grab to Community (monthly)
            $table->integer('daily_report_actual')->default(0);  // Daily Report (kunjungan)

            $table->timestamps();

            $table->unique(['period', 'user_id']);
            $table->index(['period']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_ao_activity_inputs');
    }
};
