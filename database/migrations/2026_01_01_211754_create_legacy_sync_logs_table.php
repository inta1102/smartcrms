<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('legacy_sync_logs', function (Blueprint $table) {
            $table->id();

            $table->date('position_date')->index();

            $table->enum('status', ['running','success','failed'])->default('running')->index();
            $table->string('batch_id', 64)->nullable()->index();

            $table->unsignedInteger('total_cases')->default(0);
            $table->unsignedInteger('synced_cases')->default(0);
            $table->unsignedInteger('skipped_cases')->default(0);
            $table->unsignedInteger('failed_cases')->default(0);

            $table->text('message')->nullable();

            $table->foreignId('run_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // 1 posisi boleh berkali2 running, tapi biasanya kita ambil latest
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_sync_logs');
    }
};
