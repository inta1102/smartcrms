<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('communities', function (Blueprint $table) {
            $table->id();

            $table->string('code', 30)->nullable()->unique(); // opsional
            $table->string('name');
            $table->string('type', 30)->nullable();           // paguyuban/koperasi/instansi/cluster
            $table->string('segment', 30)->nullable();        // UMKM/pegawai/dll

            $table->string('address')->nullable();
            $table->string('village', 60)->nullable();
            $table->string('district', 60)->nullable();
            $table->string('city', 60)->nullable();

            $table->string('pic_name')->nullable();
            $table->string('pic_phone', 30)->nullable();
            $table->string('pic_position', 60)->nullable();

            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active'); // active/inactive

            $table->unsignedBigInteger('created_by')->nullable(); // siapa input awal
            $table->timestamps();

            $table->index(['status']);
            $table->index(['city', 'district']);
            $table->index(['name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communities');
    }
};
