<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // Hilangkan ON UPDATE, biarkan changed_at jadi timestamp biasa.
        // Kalau kamu mau wajib isi, tetap NOT NULL dengan default CURRENT_TIMESTAMP.
        DB::statement("
            ALTER TABLE legal_action_status_logs
            MODIFY changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ");
    }

    public function down(): void
    {
        // Kembalikan seperti semula (kalau kamu memang butuh).
        DB::statement("
            ALTER TABLE legal_action_status_logs
            MODIFY changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ");
    }
};
