<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rs_action_statuses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('loan_account_id')
                ->constrained('loan_accounts')
                ->cascadeOnDelete();

            // snapshot yang sedang dimonitor
            $table->date('position_date');

            // status action
            $table->string('status', 20)->default('none'); // none|contacted|visit|done

            // channel (opsional)
            $table->string('channel', 20)->nullable(); // wa|call|visit|other

            $table->string('note', 255)->nullable();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // 1 status per rekening per snapshot
            $table->unique(['loan_account_id', 'position_date'], 'rs_action_unique');
            $table->index(['position_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rs_action_statuses');
    }
};
