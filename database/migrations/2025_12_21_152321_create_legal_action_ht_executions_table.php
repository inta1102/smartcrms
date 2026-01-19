<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_action_ht_executions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('legal_action_id')->unique();
            $table->foreign('legal_action_id')
                ->references('id')->on('legal_actions')
                ->onDelete('cascade');

            // metode eksekusi HT
            $table->string('method', 30); // parate | pn | bawah_tangan

            // dasar/trigger
            $table->date('basis_default_at')->nullable();

            // ringkasan objek HT
            $table->text('collateral_summary')->nullable();

            // data HT / jaminan
            $table->string('ht_deed_no', 100)->nullable();       // APHT no
            $table->string('ht_cert_no', 100)->nullable();       // Sertifikat HT
            $table->string('land_cert_type', 30)->nullable();    // SHM/SHGB/SHMSRS/...
            $table->string('land_cert_no', 100)->nullable();
            $table->string('owner_name', 255)->nullable();
            $table->text('object_address')->nullable();

            // nilai
            $table->decimal('appraisal_value', 18, 2)->nullable();
            $table->decimal('outstanding_at_start', 18, 2)->nullable();

            $table->text('notes')->nullable();

            // locking untuk read-only saat sudah submitted
            $table->timestamp('locked_at')->nullable();
            $table->string('lock_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_action_ht_executions');
    }
};
