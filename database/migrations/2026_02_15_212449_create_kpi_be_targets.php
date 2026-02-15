<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('kpi_be_targets', function (Blueprint $table) {
            $table->id();

            // simpan period sebagai tanggal awal bulan (YYYY-MM-01)
            $table->date('period');

            $table->unsignedBigInteger('be_user_id');

            $table->decimal('target_os_selesai', 18, 2)->default(0); // nominal Rp
            $table->unsignedInteger('target_noa_selesai')->default(0);
            $table->decimal('target_bunga_masuk', 18, 2)->default(0);
            $table->decimal('target_denda_masuk', 18, 2)->default(0);

            $table->timestamps();

            $table->unique(['period','be_user_id'], 'uq_kpi_be_targets_period_user');
            $table->index(['be_user_id','period']);

            // FK users (opsional kalau sudah ada)
            $table->foreign('be_user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_be_targets');
    }
};
