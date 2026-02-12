<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ro_visit_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ro_visit_id');
            $table->string('path', 255);       // /uploads/ro-visits/2026/02/xxx.jpg
            $table->string('caption', 120)->nullable();
            $table->unsignedInteger('sort_no')->default(0);
            $table->timestamps();

            $table->index(['ro_visit_id','sort_no']);
            $table->foreign('ro_visit_id')->references('id')->on('ro_visits')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ro_visit_photos');
    }
};
