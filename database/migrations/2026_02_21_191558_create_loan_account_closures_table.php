<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_account_closures', function (Blueprint $table) {
            $table->bigIncrements('id');

            // =========================
            // Identity (from CBS)
            // =========================
            $table->string('account_no', 32)->index();     // nofas
            $table->string('cif', 32)->nullable()->index(); // cno
            $table->string('ao_code', 12)->nullable()->index(); // kdcollector (kadang lebih dari 6, amankan)

            // =========================
            // Closure event
            // =========================
            $table->date('closed_date')->index();         // tgllunas
            $table->char('closed_month', 7)->index();     // YYYY-MM derived from closed_date

            // Standard status in system: LUNAS / WRITE_OFF / AYDA / OTHER
            $table->string('close_type', 20)->index();

            // Keep raw CBS status: LN / WO / AYDA (audit trail)
            $table->string('source_status_raw', 20)->nullable()->index();

            // =========================
            // Amounts (optional but useful)
            // =========================
            $table->decimal('os_at_prev_snapshot', 18, 2)->nullable(); // sldakhir (as provided by CBS)
            $table->decimal('os_closed', 18, 2)->nullable(); // biasanya 0 untuk LUNAS (optional)

            // =========================
            // Import audit
            // =========================
            $table->string('source_file', 255)->nullable();
            $table->string('import_batch_id', 64)->nullable()->index();
            $table->dateTime('imported_at')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            // =========================
            // Idempotent unique key for import
            // =========================
            $table->unique(['account_no', 'closed_date', 'close_type'], 'uq_closure_account_date_type');

            // Optional: membantu query KPI yang sering pakai month + type
            $table->index(['closed_month', 'close_type'], 'idx_closure_month_type');

            // Optional: membantu lookup LUNAS cepat per account + month
            $table->index(['account_no', 'closed_month'], 'idx_closure_account_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_account_closures');
    }
};