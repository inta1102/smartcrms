<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_action_ht_auctions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('legal_action_id');
            $table->foreign('legal_action_id')
                ->references('id')->on('legal_actions')
                ->onDelete('cascade');

            $table->unsignedInteger('attempt_no')->default(1);

            $table->string('kpknl_office', 255)->nullable();
            $table->string('registration_no', 150)->nullable();

            $table->decimal('limit_value', 18, 2)->nullable();
            $table->date('auction_date')->nullable();

            $table->string('auction_result', 20)->nullable(); // laku|tidak_laku|batal|tunda
            $table->decimal('sold_value', 18, 2)->nullable();

            $table->string('winner_name', 255)->nullable();
            $table->date('settlement_date')->nullable();

            $table->string('minutes_file_path', 500)->nullable(); // risalah lelang
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['legal_action_id', 'attempt_no']);
            $table->index(['auction_date']);
            $table->index(['auction_result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_action_ht_auctions');
    }
};
