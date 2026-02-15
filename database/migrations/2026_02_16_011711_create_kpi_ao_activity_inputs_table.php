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

            // input manual (leader / TL / KSL / KBL sesuai kebijakanmu)
            $table->integer('community_actual')->default(0);     // Grab to community (monthly)
            $table->integer('daily_report_actual')->default(0);  // Daily report / kunjungan (monthly count)

            $table->timestamps();

            $table->unique(['period', 'user_id'], 'uq_kpi_ao_activity_inputs_period_user');

            // optional FK (kalau users table di db yang sama)
            // $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_ao_activity_inputs');
    }
};
