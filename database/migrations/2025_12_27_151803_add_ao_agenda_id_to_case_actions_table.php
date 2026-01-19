<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('case_actions', function (Blueprint $table) {
            $table->unsignedBigInteger('ao_agenda_id')->nullable()->after('npl_case_id');

            $table->index(['ao_agenda_id', 'action_at'], 'idx_case_actions_agenda_actionat');
            $table->index(['npl_case_id', 'action_at'], 'idx_case_actions_case_actionat');
        });
    }

    public function down(): void
    {
        Schema::table('case_actions', function (Blueprint $table) {
            $table->dropIndex('idx_case_actions_agenda_actionat');
            $table->dropIndex('idx_case_actions_case_actionat');
            $table->dropColumn('ao_agenda_id');
        });
    }
};
