<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_admin_checklists', function (Blueprint $table) {
            $table->id();

            $table->foreignId('legal_action_id')->constrained()->cascadeOnDelete();

            // kode unik per item checklist (per jenis)
            $table->string('check_code', 60);    // ex: HT_VALID
            $table->string('check_label', 255);  // ex: Hak Tanggungan masih berlaku
            $table->boolean('is_required')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            // hanya TL yang boleh mengubah ini
            $table->boolean('is_checked')->default(false);
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->text('notes')->nullable(); // catatan TL (opsional)

            $table->timestamps();

            // 1 action tidak boleh punya kode checklist yang sama
            $table->unique(['legal_action_id', 'check_code'], 'lac_action_code_unique');
            $table->index(['legal_action_id', 'is_required', 'is_checked'], 'lac_action_required_checked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_admin_checklists');
    }
};
