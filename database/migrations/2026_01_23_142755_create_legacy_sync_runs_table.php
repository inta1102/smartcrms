<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_sync_runs', function (Blueprint $t) {
            $t->id();

            // posisi data yang dipakai Step 2
            $t->date('posisi_date');

            // progress counters
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('processed')->default(0);
            $t->unsignedInteger('failed')->default(0);

            // running|done|failed|cancelled
            $t->string('status', 30)->default('running');

            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();

            // siapa yang trigger (opsional)
            $t->unsignedBigInteger('created_by')->nullable();

            $t->timestamps();

            $t->index(['posisi_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_sync_runs');
    }
};
