<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {
            if (!Schema::hasColumn('ao_agendas', 'source')) {
                $table->string('source', 50)->nullable()->after('agenda_type');
            }
            if (!Schema::hasColumn('ao_agendas', 'source_key')) {
                $table->string('source_key', 80)->nullable()->after('source');
            }

            // Unique untuk idempotency (kalau kolomnya baru)
            // Catatan: MySQL butuh nama index unik
            $table->unique(['resolution_target_id', 'source', 'source_key'], 'ao_agendas_target_source_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ao_agendas', function (Blueprint $table) {
            $table->dropUnique('ao_agendas_target_source_key_unique');
            if (Schema::hasColumn('ao_agendas', 'source_key')) $table->dropColumn('source_key');
            if (Schema::hasColumn('ao_agendas', 'source')) $table->dropColumn('source');
        });
    }
};
