<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('kpi_kbl_monthlies', function (Blueprint $table) {
            $table->unsignedInteger('dpk_base_noa')->default(0)->after('dpk_base_os');
            $table->unsignedInteger('dpk_to_npl_noa')->default(0)->after('dpk_to_npl_os');
        });
    }

    public function down()
    {
        Schema::table('kpi_kbl_monthlies', function (Blueprint $table) {
            $table->dropColumn(['dpk_base_noa','dpk_to_npl_noa']);
        });
    }
};
