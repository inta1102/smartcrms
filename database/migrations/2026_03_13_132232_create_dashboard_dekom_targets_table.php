<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('dashboard_dekom_targets', function (Blueprint $table) {
            $table->id();
            $table->date('period_month')->unique();

            $table->decimal('target_disbursement', 18, 2)->default(0);
            $table->decimal('target_os', 18, 2)->default(0);
            $table->decimal('target_npl_pct', 8, 4)->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->index('period_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_dekom_targets');
    }
};