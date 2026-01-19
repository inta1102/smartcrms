<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legal_action_ht_documents', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('legal_action_id');
            $table->foreign('legal_action_id')
                ->references('id')->on('legal_actions')
                ->onDelete('cascade');

            $table->string('doc_type', 50); // sertifikat_tanah, apht, somasi_terakhir, dst
            $table->string('doc_no', 100)->nullable();
            $table->date('doc_date')->nullable();
            $table->string('issued_by', 255)->nullable();

            $table->string('file_path', 500)->nullable();
            $table->text('remarks')->nullable();

            $table->boolean('is_required')->default(false);

            $table->string('status', 20)->default('draft'); // draft|uploaded|verified|rejected
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('verify_notes')->nullable();

            $table->timestamps();

            $table->index(['legal_action_id', 'doc_type']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_action_ht_documents');
    }
};
