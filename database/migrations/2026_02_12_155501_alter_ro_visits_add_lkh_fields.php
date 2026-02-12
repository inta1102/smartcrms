<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ro_visits', function (Blueprint $table) {
            // biar aman: user_id sebaiknya NOT NULL (kalau bisa)
            // kalau sudah ada data & takut error, skip ini
            // $table->unsignedBigInteger('user_id')->nullable(false)->change();

            $table->timestamp('visited_at')->nullable()->after('visit_date'); // jam submit kunjungan
            $table->decimal('lat', 10, 7)->nullable()->after('visited_at');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');

            // kalau lkh_note sudah ada, skip
            // $table->text('lkh_note')->nullable()->change();

            // unique agar 1 user 1 account 1 hari hanya 1 row
            $table->unique(['user_id', 'account_no', 'visit_date'], 'ro_visits_user_acc_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ro_visits', function (Blueprint $table) {
            $table->dropUnique('ro_visits_user_acc_date_unique');
            $table->dropColumn(['visited_at','lat','lng']);
        });
    }
};
