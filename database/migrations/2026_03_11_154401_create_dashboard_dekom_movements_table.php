<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dashboard_dekom_movements', function (Blueprint $table) {
            $table->id();

            $table->date('period_month')->index();
            $table->string('mode', 10)->default('eom');

            $table->string('section', 50)->index();
            $table->string('subgroup', 50)->nullable()->index();

            $table->string('line_key', 100);
            $table->string('line_label', 150);

            $table->integer('noa_count')->default(0);
            $table->decimal('os_amount', 18, 2)->default(0);
            $table->decimal('plafond_baru', 18, 2)->default(0);

            $table->integer('sort_order')->default(0);
            $table->boolean('is_total')->default(false);

            $table->json('meta')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_dekom_movements');
    }
};
