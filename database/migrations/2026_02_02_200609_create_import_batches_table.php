<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('module', 50); // installments|disbursements|...
            $table->string('source', 120)->nullable(); // CBS / manual / etc
            $table->string('filename', 255)->nullable();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_updated')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['module', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
