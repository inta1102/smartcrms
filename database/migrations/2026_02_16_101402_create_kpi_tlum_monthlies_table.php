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
        Schema::create('kpi_tlum_monthlies', function (Blueprint $table) {
            $table->id();
            $table->date('period')->index();

            $table->unsignedBigInteger('tlum_user_id')->index(); // user TLUM (leader)
            $table->string('unit_code', 50)->nullable(); // optional

            // targets
            $table->integer('noa_target')->default(0);
            $table->bigInteger('os_target')->default(0);
            $table->decimal('rr_target', 6, 2)->default(0);
            $table->integer('com_target')->default(0);
            $table->integer('day_target')->default(0);

            // actuals
            $table->integer('noa_actual')->default(0);
            $table->bigInteger('os_actual')->default(0);
            $table->decimal('rr_actual', 6, 2)->default(0);
            $table->integer('com_actual')->default(0);
            $table->integer('day_actual')->default(0);

            // pct
            $table->decimal('noa_pct', 7, 2)->default(0);
            $table->decimal('os_pct', 7, 2)->default(0);
            $table->decimal('com_pct', 7, 2)->default(0);
            $table->decimal('day_pct', 7, 2)->default(0);

            // score + PI
            $table->decimal('score_noa', 6, 2)->default(0);
            $table->decimal('score_os', 6, 2)->default(0);
            $table->decimal('score_rr', 6, 2)->default(0);
            $table->decimal('score_com', 6, 2)->default(0);

            $table->decimal('pi_total', 7, 2)->default(0);

            $table->timestamp('calculated_at')->nullable();
            $table->timestamps();

            $table->unique(['period','tlum_user_id'], 'uq_tlum_period_user');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kpi_tlum_monthlies');
    }
};
