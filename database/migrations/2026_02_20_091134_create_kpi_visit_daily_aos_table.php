<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_visit_daily_aos', function (Blueprint $table) {
            $table->id();

            // snapshot date
            $table->date('date');

            // scope identity
            $table->string('ao_code', 6)->index();

            // core metrics
            $table->unsignedInteger('planned_total')->default(0);
            $table->unsignedInteger('done_total')->default(0);
            $table->unsignedInteger('overdue_total')->default(0);

            // ratio (0..100) with 1 decimal
            $table->decimal('vcr_pct', 5, 1)->default(0);

            // quality metrics (optional but recommended)
            $table->unsignedInteger('done_with_geo')->default(0);
            $table->unsignedInteger('done_with_photo')->default(0);
            $table->unsignedInteger('done_with_note')->default(0);

            // extra debug/info
            $table->json('meta')->nullable();

            $table->timestamps();

            // unique key: 1 row per day per AO
            $table->unique(['date', 'ao_code'], 'uniq_kpi_visit_daily_aos_date_ao');
            $table->index(['date', 'ao_code'], 'idx_kpi_visit_daily_aos_date_ao');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_visit_daily_aos');
    }
};