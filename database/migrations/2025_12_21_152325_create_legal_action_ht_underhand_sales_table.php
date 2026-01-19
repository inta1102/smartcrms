<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_action_ht_underhand_sales', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('legal_action_id')->unique();
            $table->foreign('legal_action_id')
                ->references('id')->on('legal_actions')
                ->onDelete('cascade');

            $table->date('agreement_date')->nullable();

            $table->string('buyer_name', 255)->nullable();
            $table->decimal('sale_value', 18, 2)->nullable();
            $table->string('payment_method', 100)->nullable();

            $table->date('handover_date')->nullable();

            $table->string('agreement_file_path', 500)->nullable();
            $table->string('proof_payment_file_path', 500)->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['agreement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_action_ht_underhand_sales');
    }
};
