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
        Schema::create('ro_visits', function (Blueprint $table) {
            $table->id();
            $table->string('account_no', 30);
            $table->string('ao_code', 6)->nullable();
            $table->date('visit_date');              // tanggal visit (hari ini)
            $table->enum('status', ['planned','done'])->default('planned');
            $table->text('lkh_note')->nullable();    // isi LKH sore
            $table->timestamps();

            $table->index(['account_no','visit_date']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ro_visits');
    }
};
