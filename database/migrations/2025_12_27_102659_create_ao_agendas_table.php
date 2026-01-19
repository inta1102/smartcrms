<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ao_agendas', function (Blueprint $table) {
            $table->id();

            // Relasi utama
            $table->unsignedBigInteger('npl_case_id');
            $table->unsignedBigInteger('resolution_target_id')->nullable(); // boleh null untuk agenda umum kasus

            // AO PIC (user)
            $table->unsignedBigInteger('ao_id');

            // Tipe agenda: visit/call/wa/sp/nonlit/lit/dll
            $table->string('agenda_type', 30);

            // Jadwal
            $table->dateTime('planned_at')->nullable(); // kapan direncanakan dilakukan
            $table->dateTime('due_at')->nullable();     // batas akhir/tempo

            // Status eksekusi agenda
            // planned | done | skipped | overdue | canceled
            $table->string('status', 20)->default('planned');

            // Ringkasan hasil (diisi saat done/skip)
            $table->text('result_summary')->nullable();

            // Apakah wajib bukti (WA screenshot, foto visit, dsb)
            $table->boolean('evidence_required')->default(false);

            // Audit trail ringan (siapa buat/ubah)
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            // Indexes (buat performa filter dashboard AO/Overdue)
            $table->index(['npl_case_id']);
            $table->index(['resolution_target_id']);
            $table->index(['ao_id', 'status']);
            $table->index(['due_at', 'status']);
            $table->index(['agenda_type']);

            // Foreign keys
            $table->foreign('npl_case_id')
                ->references('id')->on('npl_cases')
                ->cascadeOnDelete();

            $table->foreign('resolution_target_id')
                ->references('id')->on('case_resolution_targets')
                ->nullOnDelete();

            $table->foreign('ao_id')
                ->references('id')->on('users')
                ->restrictOnDelete();

            $table->foreign('created_by')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->foreign('updated_by')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ao_agendas');
    }
};
