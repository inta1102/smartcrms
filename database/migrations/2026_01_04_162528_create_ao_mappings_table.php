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
        Schema::create('ao_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 20)->index();
            $table->string('ao_code', 20)->index(); // harus match loan_accounts.ao_code
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['employee_code', 'ao_code']);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ao_mappings');
    }
};
