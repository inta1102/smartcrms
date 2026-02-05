<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kpi_so_targets', function (Blueprint $table) {
            $table->text('submit_note')->nullable()->after('target_activity');
            $table->text('review_note')->nullable()->after('submit_note');

            $table->unsignedBigInteger('tl_approved_by')->nullable()->after('review_note');
            $table->dateTime('tl_approved_at')->nullable()->after('tl_approved_by');

            $table->unsignedBigInteger('rejected_by')->nullable()->after('tl_approved_at');
            $table->dateTime('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejected_note')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('kpi_so_targets', function (Blueprint $table) {
            $table->dropColumn([
                'submit_note','review_note',
                'tl_approved_by','tl_approved_at',
                'rejected_by','rejected_at','rejected_note',
            ]);
        });
    }
};
