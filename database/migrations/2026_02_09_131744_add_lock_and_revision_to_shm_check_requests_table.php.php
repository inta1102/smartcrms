<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shm_check_requests', function (Blueprint $table) {
            // ✅ Lock saat SAD/KSA download KTP/SHM
            $table->timestamp('initial_files_locked_at')->nullable()->after('submitted_at');
            $table->unsignedBigInteger('initial_files_locked_by')->nullable()->after('initial_files_locked_at');

            // ✅ Revisi dokumen initial (KTP/SHM)
            $table->timestamp('revision_requested_at')->nullable()->after('initial_files_locked_by');
            $table->unsignedBigInteger('revision_requested_by')->nullable()->after('revision_requested_at');
            $table->text('revision_reason')->nullable()->after('revision_requested_by');

            $table->timestamp('revision_approved_at')->nullable()->after('revision_reason');
            $table->unsignedBigInteger('revision_approved_by')->nullable()->after('revision_approved_at');
            $table->text('revision_approval_notes')->nullable()->after('revision_approved_by');

            $table->index(['initial_files_locked_at']);
            $table->index(['revision_requested_at']);
        });
    }

    public function down(): void
    {
        Schema::table('shm_check_requests', function (Blueprint $table) {
            $table->dropIndex(['initial_files_locked_at']);
            $table->dropIndex(['revision_requested_at']);

            $table->dropColumn([
                'initial_files_locked_at',
                'initial_files_locked_by',
                'revision_requested_at',
                'revision_requested_by',
                'revision_reason',
                'revision_approved_at',
                'revision_approved_by',
                'revision_approval_notes',
            ]);
        });
    }
};
