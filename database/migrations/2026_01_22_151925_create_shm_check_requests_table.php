<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shm_check_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_no', 30)->unique();

            $table->foreignId('requested_by')->constrained('users');

            $table->string('branch_code', 20)->nullable();
            $table->string('ao_code', 20)->nullable();

            $table->string('debtor_name', 191);
            $table->string('debtor_phone', 50)->nullable();
            $table->string('collateral_address', 255)->nullable();
            $table->string('certificate_no', 100)->nullable();
            $table->string('notary_name', 191)->nullable();

            $table->string('status', 40)->index();

            $table->dateTime('submitted_at')->nullable();
            $table->dateTime('sent_to_notary_at')->nullable();
            $table->dateTime('sp_sk_uploaded_at')->nullable();
            $table->dateTime('signed_uploaded_at')->nullable();
            $table->dateTime('handed_to_sad_at')->nullable();
            $table->dateTime('sent_to_bpn_at')->nullable();
            $table->dateTime('result_uploaded_at')->nullable();
            $table->dateTime('closed_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shm_check_requests');
    }
};
