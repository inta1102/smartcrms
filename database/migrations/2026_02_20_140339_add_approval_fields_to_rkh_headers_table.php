<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('rkh_headers', function (Blueprint $table) {
            
            $table->unsignedBigInteger('rejected_by')->nullable()->after('approval_note');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_note')->nullable()->after('rejected_at');

            $table->index(['status', 'tanggal']);
            $table->index(['user_id', 'tanggal']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rkh_headers', function (Blueprint $table) {
            //
        });
    }
};
