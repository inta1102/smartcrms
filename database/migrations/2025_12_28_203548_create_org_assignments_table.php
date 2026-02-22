<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_assignments', function (Blueprint $table) {
            $table->id();

            // 🔗 Relasi struktur
            $table->foreignId('user_id')
                ->comment('Bawahan / staff / AO')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('leader_id')
                ->comment('Atasan langsung: TL / KASI / Kabag')
                ->constrained('users')
                ->cascadeOnDelete();

            // 🔖 Konteks jabatan atasan
            $table->string('leader_role', 50)
                ->comment('Role atasan saat ini: tll, kslu, kslr, kbl, dll');

            // 🏢 Unit kerja (opsional tapi sangat disarankan)
            $table->string('unit_code', 50)
                ->nullable()
                ->comment('Contoh: lending, remedial, funding, ops');

            // ⏱️ Histori & audit
            $table->date('effective_from')
                ->comment('Tanggal mulai struktur berlaku');

            $table->date('effective_to')
                ->nullable()
                ->comment('Tanggal akhir (NULL = masih aktif)');

            $table->boolean('is_active')
                ->default(true)
                ->index()
                ->comment('Shortcut status aktif');

            // 🧾 Audit
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // 🔎 Index penting
            $table->index(['leader_id', 'leader_role', 'is_active'], 'idx_org_leader_active');
            $table->index(['user_id', 'is_active'], 'idx_org_user_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_assignments');
    }
};
