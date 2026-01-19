<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_action_ht_events', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('legal_action_id');
            $table->foreign('legal_action_id')
                ->references('id')->on('legal_actions')
                ->onDelete('cascade');

            $table->string('event_type', 50); // submit_kpknl, schedule_lelang, pn_fiat_received, ...
            $table->timestamp('event_at')->nullable();
            $table->string('ref_no', 150)->nullable(); // no agenda/registrasi
            $table->json('payload')->nullable();       // fleksibel (nilai limit, hasil, dll)
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['legal_action_id', 'event_type']);
            $table->index(['event_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_action_ht_events');
    }
};
