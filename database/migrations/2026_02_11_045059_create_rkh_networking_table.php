<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rkh_networking', function (Blueprint $table) {
            $table->id();

            $table->foreignId('rkh_detail_id')->constrained('rkh_details')->cascadeOnDelete();

            $table->string('nama_relasi');
            $table->enum('jenis_relasi', ['supplier', 'komunitas', 'tokoh', 'umkm', 'lainnya'])->default('lainnya');

            $table->text('potensi')->nullable();
            $table->text('follow_up')->nullable();

            $table->timestamps();

            // 1 detail kegiatan networking maksimal 1 record networking
            $table->unique(['rkh_detail_id'], 'rkh_network_unique_detail');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rkh_networking');
    }
};
