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
        Schema::create('case_actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('npl_case_id')
                ->constrained('npl_cases')
                ->onDelete('cascade');

            // PIC yang melakukan aksi (user SmartKPI)
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('source_system', 30)->default('app');

            $table->dateTime('action_at');              // kapan tindakan dilakukan
            $table->string('action_type', 50);          // telpon, kunjungan, surat, WA, SP1, SP2, dsb
            $table->text('description')->nullable();    // catatan apa yang terjadi
            $table->string('result', 100)->nullable();  // berhasil, janji bayar, tidak ketemu, dsb

            // Rencana tindak lanjut
            $table->string('next_action')->nullable();
            $table->date('next_action_due')->nullable();

            $table->timestamps();

            $table->index(['action_at', 'action_type']);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('case_actions');
    }
};
