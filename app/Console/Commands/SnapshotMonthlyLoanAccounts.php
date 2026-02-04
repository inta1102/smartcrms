<?php

namespace App\Console\Commands;

use App\Models\LoanAccount;
use App\Models\LoanAccountSnapshotMonthly;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SnapshotMonthlyLoanAccounts extends Command
{
    protected $signature = 'crms:snapshot-loan-monthly
                            {--month= : Format YYYY-MM (default: current month)}
                            {--position_date= : Pakai position_date tertentu sebagai sumber (default: latest)}';

    protected $description = 'Create/refresh monthly snapshot of loan accounts for growth MoM';

    public function handle(): int
    {
        $monthOpt        = $this->option('month');
        $positionDateOpt = $this->option('position_date');

        $snapshotMonth = $monthOpt
            ? Carbon::createFromFormat('Y-m', $monthOpt)->startOfMonth()->toDateString()
            : now()->startOfMonth()->toDateString();

        $sourcePositionDate = $positionDateOpt ?: LoanAccount::query()
            ->selectRaw('MAX(position_date) as d')
            ->value('d');

        if (!$sourcePositionDate) {
            $this->error('Tidak ada data loan_accounts untuk disnapshot.');
            return self::FAILURE;
        }

        $this->info("Snapshot month  : {$snapshotMonth}");
        $this->info("Source position : {$sourcePositionDate}");
        $this->info("Mulai snapshot...");

        $total = LoanAccount::query()
            ->whereDate('position_date', $sourcePositionDate)
            ->count();

        $this->info("Total rows sumber: {$total}");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // chunk supaya aman
        LoanAccount::query()
            ->whereDate('position_date', $sourcePositionDate)
            ->orderBy('id')
            ->select([
                'id', // ✅ WAJIB untuk chunkById
                'account_no',
                'cif',
                'customer_name',
                'branch_code',
                'ao_code',
                'outstanding',
                'dpd',
                'kolek',

                // ✅ baru: RR (tanpa tunggakan)
                'ft_pokok',
                'ft_bunga',
            ])
            ->chunkById(500, function ($rows) use ($snapshotMonth, $sourcePositionDate, $bar) {
                $payload = [];
                $now = now();

                foreach ($rows as $r) {
                    $payload[] = [
                        'snapshot_month'       => $snapshotMonth,
                        'account_no'           => $r->account_no,
                        'cif'                  => $r->cif ?? null,
                        'customer_name'        => $r->customer_name ?? null,
                        'branch_code'          => $r->branch_code ?? null,
                        'ao_code'              => $r->ao_code ?? null,
                        'outstanding'          => $r->outstanding ?? 0,
                        'dpd'                  => $r->dpd ?? 0,
                        'kolek'                => $r->kolek ?? null,

                        // ✅ baru
                        'ft_pokok'             => (int)($r->ft_pokok ?? 0),
                        'ft_bunga'             => (int)($r->ft_bunga ?? 0),

                        'source_position_date' => $sourcePositionDate,
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ];
                }

                // upsert per bulan + account_no
                LoanAccountSnapshotMonthly::query()->upsert(
                    $payload,
                    ['snapshot_month', 'account_no'],
                    [
                        'cif',
                        'customer_name',
                        'branch_code',
                        'ao_code',
                        'outstanding',
                        'dpd',
                        'kolek',

                        // ✅ baru
                        'ft_pokok',
                        'ft_bunga',

                        'source_position_date',
                        'updated_at',
                    ]
                );

                $bar->advance(count($rows));
            });

        $bar->finish();
        $this->newLine();
        $this->info("✅ Snapshot selesai.");

        return self::SUCCESS;
    }
}
