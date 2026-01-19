<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {
            // ubah ao_id jadi nullable
            $table->unsignedBigInteger('ao_id')
                  ->nullable()
                  ->change();
        });
    }

    public function down(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {
            // balikin ke NOT NULL (hati-hati kalau sudah ada data null)
            $table->unsignedBigInteger('ao_id')
                  ->nullable(false)
                  ->change();
        });
    }
};
