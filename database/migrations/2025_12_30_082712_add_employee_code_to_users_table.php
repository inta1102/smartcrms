<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_code', 20)->nullable()->after('email');
            $table->index('employee_code');

            // Kalau kamu yakin tidak ada duplikasi, boleh aktifkan unique:
            // $table->unique('employee_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // kalau unique, dropUnique dulu
            // $table->dropUnique(['employee_code']);
            $table->dropIndex(['employee_code']);
            $table->dropColumn('employee_code');
        });
    }
};
