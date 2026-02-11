<?php

// database/migrations/xxxx_xx_xx_xxxxxx_add_visit_schedule_id_to_rkh_details.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rkh_details', function (Blueprint $table) {
            $table->unsignedBigInteger('visit_schedule_id')->nullable()->after('id');
            $table->index('visit_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::table('rkh_details', function (Blueprint $table) {
            $table->dropIndex(['visit_schedule_id']);
            $table->dropColumn('visit_schedule_id');
        });
    }
};
