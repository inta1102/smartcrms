<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_so_community_inputs', function (Blueprint $table) {
            $table->id();

            // period selalu startOfMonth (YYYY-MM-01)
            $table->date('period')->index();

            $table->unsignedBigInteger('user_id')->index(); // SO user id

            // input manual
            $table->unsignedInteger('handling_actual')->default(0);
            $table->unsignedBigInteger('os_adjustment')->default(0); // rupiah pengurang OS (titipan)

            // audit
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->unique(['period', 'user_id'], 'kpi_so_community_inputs_period_user_unique');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_so_community_inputs');
    }
};
