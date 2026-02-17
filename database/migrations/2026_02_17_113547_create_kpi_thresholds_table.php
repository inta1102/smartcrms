<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_thresholds', function (Blueprint $table) {
            $table->id();

            // contoh: 'rr_pct'
            $table->string('metric', 50)->unique();

            // label utk UI
            $table->string('title', 100);

            // arah: higher_is_better / lower_is_better
            $table->string('direction', 20)->default('higher_is_better');

            // batas status (pakai decimal biar aman)
            // untuk higher_is_better: AMAN >= green_min, WASPADA >= yellow_min, RISIKO < yellow_min
            $table->decimal('green_min', 7, 2)->nullable();
            $table->decimal('yellow_min', 7, 2)->nullable();

            // opsional (kalau mau ada ambang merah menyala dsb nanti)
            $table->decimal('red_min', 7, 2)->nullable();

            $table->boolean('is_active')->default(true);

            // audit
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_thresholds');
    }
};
