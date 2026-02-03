<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();

            $table->string('account_no', 40);
            $table->string('ao_code', 20)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();

            $table->date('period');   // bulan jatuh tempo (YYYY-MM-01)
            $table->date('due_date');
            $table->bigInteger('due_amount')->default(0);

            $table->date('paid_date')->nullable();
            $table->bigInteger('paid_amount')->nullable();

            $table->boolean('is_paid')->default(false);
            $table->boolean('is_paid_ontime')->default(false);
            $table->integer('days_late')->default(0);

            $table->string('source_file', 255)->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable();

            $table->timestamps();

            $table->unique(['account_no', 'due_date']);
            $table->index(['period', 'ao_code']);
            $table->index(['period', 'account_no']);
            $table->index(['due_date']);

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('import_batch_id')->references('id')->on('import_batches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
    }
};
