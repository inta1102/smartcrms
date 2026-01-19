<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('case_actions', function (Blueprint $table) {
            // pastikan doctrine/dbal ada jika pakai change()
            $table->string('source_ref_id', 80)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('case_actions', function (Blueprint $table) {
            $table->unsignedBigInteger('source_ref_id')->nullable()->change();
        });
    }
};

