<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('community_handlings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('community_id')->constrained('communities')->cascadeOnDelete();

            $table->unsignedBigInteger('user_id'); // users.id
            $table->string('role', 10);            // AO / SO

            $table->date('period_from');           // start (biasanya startOfMonth)
            $table->date('period_to')->nullable(); // end

            $table->unsignedBigInteger('input_by')->nullable();
            $table->timestamps();

            $table->unique(['community_id','user_id','role','period_from']);

            $table->index(['role','user_id','period_from','period_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_handlings');
    }
};
