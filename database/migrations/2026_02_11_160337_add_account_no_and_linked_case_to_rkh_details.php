<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rkh_details', function (Blueprint $table) {
            $table->string('account_no', 255)->nullable()->index()->after('nasabah_id');
            $table->unsignedBigInteger('linked_npl_case_id')->nullable()->index()->after('account_no');
        });
    }

    public function down(): void
    {
        Schema::table('rkh_details', function (Blueprint $table) {
            $table->dropColumn(['account_no', 'linked_npl_case_id']);
        });
    }
};
