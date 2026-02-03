<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loan_disbursements', function (Blueprint $table) {
            $table->id();

            $table->string('account_no', 40);
            $table->string('ao_code', 20)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->date('disb_date');
            $table->date('period'); // YYYY-MM-01 (bulan pencairan)
            $table->bigInteger('amount')->default(0);

            $table->string('cif', 40)->nullable();
            $table->string('customer_name', 120)->nullable();

            $table->string('source_file', 255)->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();

            $table->timestamps();

            $table->unique(['account_no', 'disb_date', 'amount']);
            $table->index(['period', 'ao_code']);
            $table->index(['period', 'account_no']);
            $table->index(['disb_date']);

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('import_batch_id')->references('id')->on('import_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_disbursements');
    }
};
