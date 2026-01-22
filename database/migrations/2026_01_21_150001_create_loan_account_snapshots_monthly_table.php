<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loan_account_snapshots_monthly', function (Blueprint $table) {
            $table->id();

            // Kunci bulan: pakai tanggal 1 setiap bulan (mis: 2026-01-01)
            $table->date('snapshot_month');

            // kunci kredit stabil
            $table->string('account_no', 50);

            // metadata
            $table->string('cif', 50)->nullable();
            $table->string('customer_name', 191)->nullable();

            $table->string('branch_code', 20)->nullable();
            $table->string('ao_code', 20)->nullable();

            // angka inti
            $table->decimal('outstanding', 18, 2)->default(0);
            $table->integer('dpd')->default(0);
            $table->unsignedTinyInteger('kolek')->nullable();

            // optional: untuk trace posisi data asal
            $table->date('source_position_date')->nullable();

            $table->timestamps();

            $table->unique(['snapshot_month', 'account_no'], 'uq_snap_month_account');
            $table->index(['snapshot_month', 'ao_code'], 'idx_snap_month_ao');
            $table->index(['snapshot_month', 'branch_code'], 'idx_snap_month_branch');
            $table->index(['snapshot_month', 'account_no'], 'idx_snap_month_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_account_snapshots_monthly');
    }
};
