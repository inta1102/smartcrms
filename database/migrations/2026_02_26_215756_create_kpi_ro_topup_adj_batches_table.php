<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_ro_topup_adj_batches', function (Blueprint $table) {
            $table->id();

            // Bulan KPI (YYYY-MM-01)
            $table->date('period_month');

            // draft | approved | cancelled
            $table->enum('status', ['draft','approved','cancelled'])
                  ->default('draft');

            // User yang buat draft
            $table->unsignedBigInteger('created_by');

            // Approve hanya KBL
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            // Cutoff data saat approve (realtime freeze)
            $table->date('approved_as_of_date')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            // Index penting
            $table->index(['period_month','status']);
            $table->index('created_by');
            $table->index('approved_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_ro_topup_adj_batches');
    }
};