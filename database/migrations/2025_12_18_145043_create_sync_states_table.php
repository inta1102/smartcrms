<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // contoh: users_sync
            $table->text('value')->nullable(); // simpan last_since ISO
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_states');
    }
};
