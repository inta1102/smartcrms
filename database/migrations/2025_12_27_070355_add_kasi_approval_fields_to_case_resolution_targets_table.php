<?php

// database/migrations/xxxx_add_kasi_approval_fields_to_case_resolution_targets_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('case_resolution_targets', function (Blueprint $table) {

            // Kasi approval
            $table->unsignedBigInteger('kasi_approved_by')->nullable()->after('tl_approved_at');
            $table->dateTime('kasi_approved_at')->nullable()->after('kasi_approved_by');
            $table->string('kasi_notes', 500)->nullable()->after('kasi_approved_at');

            // Active lifecycle
            $table->unsignedBigInteger('activated_by')->nullable()->after('kasi_notes');
            $table->dateTime('activated_at')->nullable()->after('activated_by');

            $table->unsignedBigInteger('deactivated_by')->nullable()->after('activated_at');
            $table->dateTime('deactivated_at')->nullable()->after('deactivated_by');
            $table->string('deactivated_reason', 200)->nullable()->after('deactivated_at');

            $table->foreign('kasi_approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('activated_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('deactivated_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('case_resolution_targets', function (Blueprint $table) {
            $table->dropForeign(['kasi_approved_by']);
            $table->dropForeign(['activated_by']);
            $table->dropForeign(['deactivated_by']);

            $table->dropColumn([
                'kasi_approved_by','kasi_approved_at','kasi_notes',
                'activated_by','activated_at',
                'deactivated_by','deactivated_at','deactivated_reason',
            ]);
        });
    }
};
