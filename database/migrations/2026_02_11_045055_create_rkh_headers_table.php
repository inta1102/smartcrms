<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rkh_headers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // RO
            $table->date('tanggal');

            // total jam kerja RO pada hari tsb (optional, bisa dihitung ulang)
            $table->decimal('total_jam', 5, 2)->default(0);

            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected'])->default('draft');

            // approval TL
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // catatan TL saat reject/approve
            $table->text('approval_note')->nullable();

            $table->timestamps();

            // 1 RO hanya boleh 1 RKH per tanggal
            $table->unique(['user_id', 'tanggal'], 'rkh_unique_user_date');
            $table->index(['tanggal', 'status'], 'rkh_date_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rkh_headers');
    }
};
