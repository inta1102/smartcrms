<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rkh_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rkh_id')->constrained('rkh_headers')->cascadeOnDelete();

            $table->time('jam_mulai');
            $table->time('jam_selesai');

            // optional kalau kamu punya tabel debitur/nasabah
            $table->unsignedBigInteger('nasabah_id')->nullable();

            // snapshot supaya walau data nasabah berubah, histori tetap konsisten
            $table->string('nama_nasabah')->nullable();

            // sesuai request: kolek akhir bulan kemarin
            $table->enum('kolektibilitas', ['L0', 'LT'])->nullable();

            // dropdown jenis kegiatan
            $table->string('jenis_kegiatan');   // refer ke master_jenis_kegiatan.code
            $table->string('tujuan_kegiatan');  // refer ke master_tujuan_kegiatan.code

            $table->string('area')->nullable(); // area/cluster kunjungan
            $table->text('catatan')->nullable();

            $table->timestamps();

            $table->index(['rkh_id', 'jam_mulai'], 'rkh_detail_time_idx');
            $table->index(['jenis_kegiatan', 'tujuan_kegiatan'], 'rkh_detail_kind_idx');
            $table->index(['nasabah_id'], 'rkh_detail_nasabah_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rkh_details');
    }
};
