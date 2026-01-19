<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('case_supervisions', function (Blueprint $table) {
            $table->id();

            // Relasi ke kasus NPL
            $table->foreignId('npl_case_id')
                ->constrained('npl_cases')
                ->cascadeOnDelete();

            // Relasi ke target (boleh null untuk supervisi umum)
            $table->foreignId('target_id')
                ->nullable()
                ->constrained('case_resolution_targets')
                ->nullOnDelete();

            // Role supervisor
            // TL | KASI
            $table->string('supervisor_role', 10);

            // Keputusan supervisi
            // approve | revise | reject | note
            $table->string('decision', 20);

            $table->string('notes', 1000)->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index(['npl_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_supervisions');
    }
};
