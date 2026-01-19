<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('legal_actions', function (Blueprint $table) {
            $table->decimal('recovery_amount', 18, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('legal_actions', function (Blueprint $table) {
            $table->decimal('recovery_amount', 18, 2)->nullable(false)->default(0)->change();
        });
    }
};

