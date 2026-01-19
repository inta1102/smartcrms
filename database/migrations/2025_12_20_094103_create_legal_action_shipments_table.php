<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_action_shipments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('legal_action_id')
                ->constrained('legal_actions')
                ->cascadeOnDelete();

            // channel pengiriman (samakan dengan controller kamu yg dulu)
            $table->string('delivery_channel', 30); // pos/kurir/petugas_bank/kuasa_hukum/lainnya/ao

            $table->string('expedition_name', 255)->nullable(); // POS/JNE/J&T
            $table->string('receipt_no', 100)->nullable();      // no resi
            $table->text('notes')->nullable();                  // catatan

            // file bukti (tanpa symlink: simpan ke public/)
            $table->string('receipt_path')->nullable();         // contoh: uploads/legal/shipping/xxx.pdf
            $table->string('receipt_original')->nullable();

            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();

            // 1 action = 1 shipment (enforced)
            $table->unique('legal_action_id');

            $table->index(['delivery_channel', 'uploaded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_action_shipments');
    }
};
