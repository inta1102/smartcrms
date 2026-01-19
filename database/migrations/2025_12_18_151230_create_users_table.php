<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            $table->string('email')->nullable()->unique();

            $table->string('password')->nullable();

            /**
             * Level / Role user
             * Contoh value:
             * ADM, BO, CS, KBO, KSA, KSO, KTI, SDM, STAFF, TI, TL, TLR
             */
            $table->string('level', 50)
                  ->nullable()
                  ->default('STAFF')
                  ->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
