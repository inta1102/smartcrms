<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('loan_installments', function (Blueprint $table) {
            // kolom transaksi
            if (!Schema::hasColumn('loan_installments', 'angske')) {
                $table->unsignedInteger('angske')->nullable()->after('period');
            }

            if (!Schema::hasColumn('loan_installments', 'principal_paid')) {
                $table->bigInteger('principal_paid')->default(0)->after('paid_amount');
            }
            if (!Schema::hasColumn('loan_installments', 'interest_paid')) {
                $table->bigInteger('interest_paid')->default(0)->after('principal_paid');
            }
            if (!Schema::hasColumn('loan_installments', 'penalty_paid')) {
                $table->bigInteger('penalty_paid')->default(0)->after('interest_paid'); // denda+penalty
            }
            if (!Schema::hasColumn('loan_installments', 'fee_paid')) {
                $table->bigInteger('fee_paid')->default(0)->after('penalty_paid'); // provisi, dll
            }

            if (!Schema::hasColumn('loan_installments', 'status')) {
                $table->string('status', 50)->nullable()->after('fee_paid');
            }
            if (!Schema::hasColumn('loan_installments', 'notes')) {
                $table->text('notes')->nullable()->after('status');
            }

            if (!Schema::hasColumn('loan_installments', 'trx_fingerprint')) {
                $table->string('trx_fingerprint', 64)->nullable()->after('notes');
                $table->unique('trx_fingerprint', 'uniq_loan_installments_fp');
            }

            // index penting utk query KPI
            $table->index(['user_id','period'], 'idx_installments_user_period');
            $table->index(['ao_code','period'], 'idx_installments_aocode_period');
            $table->index(['account_no','period'], 'idx_installments_acc_period');
            $table->index(['paid_date'], 'idx_installments_paid_date');
        });
    }

    public function down(): void
    {
        Schema::table('loan_installments', function (Blueprint $table) {
            if (Schema::hasColumn('loan_installments', 'trx_fingerprint')) {
                $table->dropUnique('uniq_loan_installments_fp');
                $table->dropColumn('trx_fingerprint');
            }

            foreach (['angske','principal_paid','interest_paid','penalty_paid','fee_paid','status','notes'] as $col) {
                if (Schema::hasColumn('loan_installments', $col)) {
                    $table->dropColumn($col);
                }
            }

            // drop indexes (kalau ada)
            foreach ([
                'idx_installments_user_period',
                'idx_installments_aocode_period',
                'idx_installments_acc_period',
                'idx_installments_paid_date',
            ] as $idx) {
                try { $table->dropIndex($idx); } catch (\Throwable $e) {}
            }
        });
    }
};
