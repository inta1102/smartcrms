<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rkh_details', function (Blueprint $table) {
            // status review TL untuk setiap item
            $table->enum('tl_status', ['pending','approved','rejected'])
                ->default('pending')
                ->after('tujuan_kegiatan');

            $table->text('tl_note')->nullable()->after('tl_status');

            $table->unsignedBigInteger('tl_reviewed_by')->nullable()->after('tl_note');
            $table->timestamp('tl_reviewed_at')->nullable()->after('tl_reviewed_by');

            $table->index(['tl_status']);
            $table->index(['tl_reviewed_by']);
        });
    }

    public function down(): void
    {
        Schema::table('rkh_details', function (Blueprint $table) {
            $table->dropIndex(['tl_status']);
            $table->dropIndex(['tl_reviewed_by']);

            $table->dropColumn([
                'tl_status','tl_note','tl_reviewed_by','tl_reviewed_at'
            ]);
        });
    }
};