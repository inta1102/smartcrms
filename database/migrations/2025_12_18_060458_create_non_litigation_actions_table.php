<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * NOTE PENTING:
     * - DB crms_db TIDAK punya tabel users (users ada di DB smartkpi).
     * - Maka kolom proposed_by/approved_by/rejected_by TIDAK dibuat foreign key.
     * - Untuk kebutuhan audit/export, disediakan snapshot nama: *_by_name.
     */
    public function up(): void
    {
        Schema::create('non_litigation_actions', function (Blueprint $table) {
            $table->id();

            // Relasi ke kasus NPL (crms_db)
            $table->unsignedBigInteger('npl_case_id');

            // Jenis non-litigasi:
            // restruct, reschedule, recondition, novasi, settlement, ptp, discount_interest, waive_penalty, dll
            $table->string('action_type', 50);

            // Status workflow:
            // draft -> submitted -> approved/rejected -> (optional) completed/canceled
            $table->string('status', 20)->default('draft');

            // Pengusul (ID user dari sistem auth kamu / smartkpi)
            $table->unsignedBigInteger('proposed_by')->nullable();
            $table->string('proposed_by_name', 100)->nullable();
            $table->dateTime('proposal_at')->nullable();

            // Ringkasan & detail usulan
            $table->string('proposal_summary', 255)->nullable();
            // Simpan JSON/text panjang (nanti di Model bisa cast ke array)
            $table->longText('proposal_detail')->nullable();

            // Nilai komitmen / nominal usulan (opsional)
            $table->decimal('commitment_amount', 18, 2)->nullable();

            // Rencana angsuran / jadwal pembayaran (opsional)
            // JSON: [{due_date, amount, note}, ...]
            $table->longText('installment_plan')->nullable();

            // Tanggal efektif setelah approved
            $table->date('effective_date')->nullable();

            // Jadwal monitoring berikutnya (untuk agenda/reminder)
            $table->date('monitoring_next_due')->nullable();

            // Jejak persetujuan (tanpa FK users karena users beda DB)
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->string('approved_by_name', 100)->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->text('approval_notes')->nullable();

            // Jejak penolakan
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->string('rejected_by_name', 100)->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->text('rejection_notes')->nullable();

            // Keterkaitan ke case_actions (jika saat approved kita create log tindakan)
            $table->unsignedBigInteger('case_action_id')->nullable();

            // Source + meta fleksibel (audit, legacy mapping jika perlu)
            $table->string('source_system', 30)->default('smartcrms');
            $table->unsignedBigInteger('source_ref_id')->nullable(); // kalau suatu saat import non-litigasi legacy
            $table->longText('meta')->nullable(); // JSON/text

            $table->timestamps();

            // Indexes
            $table->index(['npl_case_id', 'status'], 'idx_nla_case_status');
            $table->index(['action_type', 'status'], 'idx_nla_type_status');
            $table->index(['monitoring_next_due'], 'idx_nla_monitor_due');

            // Unique idempotent kalau suatu hari ada import legacy non-litigasi
            // Aman karena NULL boleh banyak di MySQL.
            $table->unique(['source_system', 'source_ref_id'], 'uq_nla_source_ref');

            // Foreign keys yang VALID di crms_db
            $table->foreign('npl_case_id')
                ->references('id')->on('npl_cases')
                ->cascadeOnDelete();

            $table->foreign('case_action_id')
                ->references('id')->on('case_actions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('non_litigation_actions');
    }
};
