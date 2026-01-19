<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {
            // taruh setelah kolom yang relevan kalau ada
            $table->dateTime('rescheduled_at')->nullable()->after('due_at');
            $table->foreignId('rescheduled_by')->nullable()->after('rescheduled_at')
                ->constrained('users')->nullOnDelete();
            $table->text('reschedule_reason')->nullable()->after('rescheduled_by');

            // opsional tapi enak untuk query/monitoring
            $table->index(['rescheduled_at']);
            $table->index(['rescheduled_by']);
        });
    }

    public function down(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {
            $table->dropIndex(['rescheduled_at']);
            $table->dropIndex(['rescheduled_by']);

            // drop FK dulu sebelum drop kolom
            $table->dropForeign(['rescheduled_by']);
            $table->dropColumn(['rescheduled_at', 'rescheduled_by', 'reschedule_reason']);
        });
    }
};
