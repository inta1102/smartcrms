<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('case_resolution_targets', function (Blueprint $table) {
            $table->id();

            // Relasi ke kasus NPL
            $table->foreignId('npl_case_id')
                ->constrained('npl_cases')
                ->cascadeOnDelete();

            // Target tanggal penyelesaian
            $table->date('target_date');

            // Strategi utama (sinkron dengan Excel komisaris)
            // lelang | rs | ayda | intensif
            $table->string('strategy', 30)->nullable();

            /**
             * Status lifecycle target
             * draft        : disimpan tapi belum diajukan
             * pending_tl   : menunggu review TL
             * pending_kasi : menunggu approval Kasi
             * approved     : target sah & aktif
             * rejected     : ditolak
             * superseded   : target lama (digantikan target baru)
             */
            $table->string('status', 20)->default('draft');

            // Penanda hanya 1 target aktif per case
            $table->boolean('is_active')->default(false);

            // Pengusul target (biasanya AO)
            $table->foreignId('proposed_by')
                ->constrained('users')
                ->restrictOnDelete();

            // Approver final (biasanya Kasi)
            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamp('approved_at')->nullable();

            // Alasan penetapan / perubahan target
            $table->string('reason', 500)->nullable();

            // Alasan penolakan (jika ditolak TL/Kasi)
            $table->string('reject_reason', 500)->nullable();

            $table->timestamps();

            // Index untuk performa & reporting
            $table->index(['npl_case_id', 'is_active']);
            $table->index(['npl_case_id', 'status']);
            $table->index('target_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_resolution_targets');
    }
};
