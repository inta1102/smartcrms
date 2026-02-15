<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // safety: kalau sudah ada (karena DB kamu sudah berisi), jangan bikin ulang
        if (Schema::hasTable('kpi_be_targets')) return;

        Schema::create('kpi_be_targets', function (Blueprint $table) {
            $table->id();

            // period disimpan sebagai tanggal awal bulan: YYYY-MM-01
            $table->date('period')->index();

            $table->unsignedBigInteger('be_user_id')->index();

            $table->decimal('target_os_selesai', 18, 2)->default(0);
            $table->unsignedInteger('target_noa_selesai')->default(0);
            $table->decimal('target_bunga_masuk', 18, 2)->default(0);
            $table->decimal('target_denda_masuk', 18, 2)->default(0);

            $table->timestamps();

            $table->unique(['period', 'be_user_id'], 'uq_kpi_be_targets_period_user');

            // FK users (kalau tabel users ada)
            $table->foreign('be_user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_be_targets');
    }
};
