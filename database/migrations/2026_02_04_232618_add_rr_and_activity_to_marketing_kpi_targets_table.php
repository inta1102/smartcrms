<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_kpi_targets', function (Blueprint $table) {
            // target baru
            $table->decimal('target_rr', 5, 2)->default(100.00)->after('target_noa');
            $table->integer('target_activity')->default(0)->after('target_rr');

            // bobot tambahan (kita kunci default)
            $table->unsignedTinyInteger('weight_rr')->default(20)->after('weight_noa');
            $table->unsignedTinyInteger('weight_activity')->default(10)->after('weight_rr');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_kpi_targets', function (Blueprint $table) {
            $table->dropColumn(['target_rr', 'target_activity', 'weight_rr', 'weight_activity']);
        });
    }
};
