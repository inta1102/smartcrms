<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('lkh_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rkh_detail_id')->constrained('rkh_details')->cascadeOnDelete();

            // laporan non tabel (naratif) tapi bisa ditarik jadi tabel
            $table->boolean('is_visited')->default(true);

            $table->text('hasil_kunjungan')->nullable();
            $table->text('respon_nasabah')->nullable();
            $table->text('tindak_lanjut')->nullable();

            // bukti opsional (foto, dokumen)
            $table->string('evidence_path')->nullable();

            $table->timestamps();

            // 1 detail RKH maksimal 1 laporan LKH
            $table->unique(['rkh_detail_id'], 'lkh_unique_detail');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lkh_reports');
    }
};
