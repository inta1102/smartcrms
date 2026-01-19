<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_add_title_to_ao_agendas_table.php
    public function up(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {
            $table->string('title', 150)->after('id'); // atau after kolom yang pas
        });
    }

    public function down(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }

};
