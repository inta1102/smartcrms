<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketing_kpi_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('period');
            $table->foreignId('user_id')->constrained('users');

            $table->decimal('os_opening', 18, 2)->default(0);
            $table->decimal('os_closing', 18, 2)->default(0);
            $table->decimal('os_growth', 18, 2)->default(0);

            $table->unsignedInteger('noa_new')->default(0);
            $table->unsignedInteger('noa_total')->nullable();

            $table->dateTime('snapshot_at')->nullable();
            $table->string('source', 30)->default('CBS_IMPORT');

            $table->timestamps();

            $table->unique(['period', 'user_id']);
            $table->index(['period']);
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_kpi_snapshots');
    }
};
