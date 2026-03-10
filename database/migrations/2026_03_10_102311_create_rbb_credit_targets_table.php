<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbb_credit_targets', function (Blueprint $table) {
            $table->id();
            $table->date('period_month'); // YYYY-MM-01
            $table->decimal('target_os', 18, 2)->default(0);
            $table->decimal('target_npl_pct', 8, 4)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('period_month', 'uq_rbb_credit_targets_period_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbb_credit_targets');
    }
};