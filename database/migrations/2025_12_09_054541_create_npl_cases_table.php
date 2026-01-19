<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
    {
        Schema::create('npl_cases', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loan_account_id')
                ->constrained('loan_accounts')
                ->onDelete('cascade');

            $table->unsignedBigInteger('pic_user_id')->nullable();

            $table->string('status', 50)->default('open');
            $table->string('priority', 20)->default('normal');

            $table->date('opened_at')->nullable();
            $table->date('closed_at')->nullable();

            $table->text('summary')->nullable();

            $table->timestamps();
            $table->index(['status', 'priority']);
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('npl_cases');
    }
};
