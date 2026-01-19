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
        Schema::create('loan_accounts', function (Blueprint $table) {
            $table->id();

            // Identitas dasar rekening
            $table->string('account_no')->index();       // no_rekening
            $table->string('cif')->nullable()->index();  // no_cif
            $table->string('customer_name');             // nama debitur

            // Info kredit
            $table->string('product_type')->nullable();  // jenis_kredit
            $table->string('segment')->nullable();       // UMKM/Pegawai/dll
            $table->unsignedTinyInteger('kolek')->nullable()->index();    // 1-5
            $table->integer('dpd')->default(0);          // days past due

            $table->decimal('plafond', 18, 2)->default(0);
            $table->decimal('outstanding', 18, 2)->default(0);
            $table->decimal('arrears_principal', 18, 2)->default(0);
            $table->decimal('arrears_interest', 18, 2)->default(0);

            // Organisasi
            $table->string('branch_code', 20)->nullable()->index();
            $table->string('branch_name')->nullable();
            $table->string('ao_code', 50)->nullable();
            $table->string('ao_name')->nullable();

            // Posisi
            $table->date('position_date')->index();      // tanggal posisi data CSV

            // Flag aktif / tidak dipakai lagi
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loan_accounts');
    }
};
