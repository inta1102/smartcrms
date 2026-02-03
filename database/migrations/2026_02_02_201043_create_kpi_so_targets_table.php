<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_so_targets', function (Blueprint $table) {
            $table->id();
            $table->date('period');
            $table->unsignedBigInteger('user_id');
            $table->string('ao_code', 20)->nullable();

            $table->bigInteger('target_os_disbursement')->default(0);
            $table->integer('target_noa_disbursement')->default(0);
            $table->decimal('target_rr', 5, 2)->default(100.00);
            $table->integer('target_activity')->default(0);

            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();

            $table->timestamps();

            $table->unique(['period', 'user_id']);
            $table->index(['period', 'ao_code']);
            $table->index(['status', 'period']);

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_so_targets');
    }
};
