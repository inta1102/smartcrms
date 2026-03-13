<?php

namespace App\Console\Commands;

use App\Models\LoanAccount;
use App\Models\LoanAccountSnapshotMonthly;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SnapshotMonthlyLoanAccounts extends Command
{
    protected $signature = 'crms:snapshot-loan-monthly
                            {--month= : Format YYYY-MM (default: month dari source_position_date)}
                            {--position_date= : Pakai position_date tertentu sebagai sumber (default: latest)}
                            {--force : Izinkan jalan walau bukan EOM}';

    protected $description = 'Create/refresh monthly snapshot of loan accounts (single version per month)';

    public function handle(): int
    {
        $monthOpt        = $this->option('month');
        $positionDateOpt = $this->option('position_date');
        $force           = (bool) $this->option('force');

        // 1) Tentukan source position_date
        $sourcePositionDate = $positionDateOpt ?: LoanAccount::query()
            ->selectRaw('MAX(position_date) as d')
            ->value('d');

        if (!$sourcePositionDate) {
            $this->error('Tidak ada data loan_accounts untuk disnapshot.');
            return self::FAILURE;
        }

        $sourceCarbon = Carbon::parse($sourcePositionDate)->startOfDay();

        // 2) Tentukan snapshot_month (pakai monthOpt kalau ada, else ikut bulan sourcePositionDate)
        $snapshotMonth = $monthOpt
            ? Carbon::createFromFormat('Y-m', $monthOpt)->startOfMonth()
            : $sourceCarbon->copy()->startOfMonth();

        // 3) Guard: wajib EOM kalau bukan force
        $isEom = $sourceCarbon->isSameDay($sourceCarbon->copy()->endOfMonth());
        if (!$isEom && !$force) {
            $this->error("Abort: source_position_date ({$sourceCarbon->toDateString()}) bukan EOM. Jalankan saat EOM atau pakai --force.");
            return self::FAILURE;
        }

        $this->info("Snapshot month  : {$snapshotMonth->toDateString()}");
        $this->info("Source position : {$sourceCarbon->toDateString()}");
        $this->info("Mode            : " . ($isEom ? "EOM" : "FORCED"));
        $this->info("Mulai snapshot...");

        $total = LoanAccount::query()
            ->whereDate('position_date', $sourceCarbon->toDateString())
            ->count();

        $this->info("Total rows sumber: {$total}");

        // 4) Refresh full: hapus snapshot_month lama dulu
        $deleted = LoanAccountSnapshotMonthly::query()
            ->whereDate('snapshot_month', $snapshotMonth->toDateString())
            ->delete();

        $this->info("Deleted rows snapshot lama: {$deleted}");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        LoanAccount::query()
            ->whereDate('position_date', $sourceCarbon->toDateString())
            ->orderBy('id')
            ->select([
                'id',
                'account_no',
                'cif',
                'customer_name',
                'branch_code',
                'ao_code',
                'outstanding',
                'dpd',
                'kolek',
                'ft_pokok',
                'ft_bunga',

                // tambahan restruktur
                'is_restructured',
                'restructure_freq',
                'last_restructure_date',
            ])
            ->chunkById(500, function ($rows) use ($snapshotMonth, $sourceCarbon, $bar) {
                $payload = [];
                $now = now();

                foreach ($rows as $r) {
                    $payload[] = [
                        'snapshot_month'       => $snapshotMonth->toDateString(),
                        'account_no'           => $r->account_no,
                        'cif'                  => $r->cif ?? null,
                        'customer_name'        => $r->customer_name ?? null,
                        'branch_code'          => $r->branch_code ?? null,
                        'ao_code'              => $r->ao_code ?? null,
                        'outstanding'          => $r->outstanding ?? 0,
                        'dpd'                  => $r->dpd ?? 0,
                        'kolek'                => $r->kolek ?? null,
                        'ft_pokok'             => (int) ($r->ft_pokok ?? 0),
                        'ft_bunga'             => (int) ($r->ft_bunga ?? 0),

                        // tambahan restruktur
                        'is_restructured'      => (int) ($r->is_restructured ?? 0),
                        'restructure_freq'     => (int) ($r->restructure_freq ?? 0),
                        'last_restructure_date'=> $r->last_restructure_date ?? null,

                        'source_position_date' => $sourceCarbon->toDateString(),
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ];
                }

                // Insert bulk (karena sudah delete full)
                LoanAccountSnapshotMonthly::query()->insert($payload);

                $bar->advance(count($rows));
            });

        $bar->finish();
        $this->newLine();
        $this->info("✅ Snapshot selesai. (single version: {$sourceCarbon->toDateString()})");

        return self::SUCCESS;
    }
}