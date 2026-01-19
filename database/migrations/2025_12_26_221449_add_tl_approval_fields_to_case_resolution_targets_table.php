<?php

// database/migrations/xxxx_add_tl_approval_fields_to_case_resolution_targets_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('case_resolution_targets', function (Blueprint $table) {
            $table->unsignedBigInteger('tl_approved_by')->nullable()->after('proposed_by');
            $table->dateTime('tl_approved_at')->nullable()->after('tl_approved_by');
            $table->string('tl_notes', 500)->nullable()->after('tl_approved_at');

            $table->foreign('tl_approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('case_resolution_targets', function (Blueprint $table) {
            $table->dropForeign(['tl_approved_by']);
            $table->dropColumn(['tl_approved_by','tl_approved_at','tl_notes']);
        });
    }
};
