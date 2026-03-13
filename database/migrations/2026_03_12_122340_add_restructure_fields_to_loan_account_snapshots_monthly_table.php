<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_account_snapshots_monthly', function (Blueprint $table) {
            if (!Schema::hasColumn('loan_account_snapshots_monthly', 'is_restructured')) {
                $table->boolean('is_restructured')
                    ->default(false)
                    ->after('dpd');
            }

            if (!Schema::hasColumn('loan_account_snapshots_monthly', 'restructure_freq')) {
                $table->unsignedTinyInteger('restructure_freq')
                    ->default(0)
                    ->after('is_restructured');
            }

            if (!Schema::hasColumn('loan_account_snapshots_monthly', 'last_restructure_date')) {
                $table->date('last_restructure_date')
                    ->nullable()
                    ->after('restructure_freq');
            }
        });

        $this->backfillMonth('2026-01-01');
        $this->backfillMonth('2026-02-01');
    }

    public function down(): void
    {
        Schema::table('loan_account_snapshots_monthly', function (Blueprint $table) {
            $drops = [];

            if (Schema::hasColumn('loan_account_snapshots_monthly', 'last_restructure_date')) {
                $drops[] = 'last_restructure_date';
            }

            if (Schema::hasColumn('loan_account_snapshots_monthly', 'restructure_freq')) {
                $drops[] = 'restructure_freq';
            }

            if (Schema::hasColumn('loan_account_snapshots_monthly', 'is_restructured')) {
                $drops[] = 'is_restructured';
            }

            if (!empty($drops)) {
                $table->dropColumn($drops);
            }
        });
    }

    private function backfillMonth(string $snapshotMonth): void
    {
        $monthStart = date('Y-m-01', strtotime($snapshotMonth));
        $monthEnd   = date('Y-m-t', strtotime($snapshotMonth));

        /*
         * Ambil row loan_accounts TERAKHIR per account_no dalam bulan tsb,
         * lalu mapping ke snapshot_month bulan yang sama.
         *
         * Catatan:
         * - diasumsikan loan_accounts menyimpan histori harian via position_date
         * - backfill ini akan mendekati kondisi historis bulanan
         */
        $sql = "
            UPDATE loan_account_snapshots_monthly s
            INNER JOIN (
                SELECT la.account_no,
                       la.is_restructured,
                       la.restructure_freq,
                       la.last_restructure_date
                FROM loan_accounts la
                INNER JOIN (
                    SELECT account_no, MAX(position_date) AS max_position_date
                    FROM loan_accounts
                    WHERE position_date BETWEEN ? AND ?
                    GROUP BY account_no
                ) x
                    ON x.account_no = la.account_no
                   AND x.max_position_date = la.position_date
            ) src
                ON src.account_no = s.account_no
            SET
                s.is_restructured = COALESCE(src.is_restructured, 0),
                s.restructure_freq = COALESCE(src.restructure_freq, 0),
                s.last_restructure_date = src.last_restructure_date
            WHERE s.snapshot_month = ?
        ";

        DB::statement($sql, [$monthStart, $monthEnd, $monthStart]);
    }
};