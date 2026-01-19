<?php

namespace App\Console\Commands;

use App\Models\NplCase;
use App\Services\CaseScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshWarningChain extends Command
{
    /**
     * Contoh pemakaian:
     * php artisan cases:refresh-warning-chain --date=2025-12-31 --ao=000030 --chunk=200
     */
    protected $signature = 'cases:refresh-warning-chain
        {--date= : Filter posisi data (loan_accounts.position_date = YYYY-MM-DD)}
        {--ao= : Filter AO code (loan_accounts.ao_code). Bisa angka/teks, akan dinormalisasi}
        {--pic= : Filter PIC user_id (npl_cases.pic_user_id)}
        {--case= : Jalankan untuk case tertentu (bisa "12" atau "12,15,20")}
        {--status=open : Status case (default open)}
        {--chunk=200 : Jumlah case per batch (default 200)}
        {--limit=0 : Batas maksimum case diproses (0 = tanpa batas)}
        {--sleep=0 : Jeda antar batch dalam milidetik (0 = tanpa jeda)}
        {--dry-run : Tampilkan target yang akan diproses tanpa eksekusi}
        {--force : Jalan tanpa konfirmasi}';

    protected $description = 'Refresh warning chain (SP1→SP2→SP3→SPT→SPJAD) secara batch/terkontrol agar tidak membebani import.';

    public function handle(): int
    {
        $date    = $this->option('date');
        $ao      = $this->option('ao');
        $pic     = $this->option('pic');
        $caseOpt = $this->option('case');
        $status  = (string)($this->option('status') ?? 'open');

        $chunk   = max(10, (int)($this->option('chunk') ?? 200));
        $limit   = max(0, (int)($this->option('limit') ?? 0));
        $sleep   = max(0, (int)($this->option('sleep') ?? 0));

        $dryRun  = (bool)$this->option('dry-run');
        $force   = (bool)$this->option('force');

        // =========================
        // 1) Lock biar tidak double-run
        // =========================
        $lockKey = 'cmd:cases:refresh-warning-chain';
        $lock = Cache::lock($lockKey, 60 * 30); // 30 menit
        if (!$lock->get()) {
            $this->error('Command sedang berjalan di proses lain (lock aktif).');
            return self::FAILURE;
        }

        try {
            // =========================
            // 2) Build query
            // =========================
            $q = NplCase::query()
                ->select([
                    'id',
                    'loan_account_id',
                    'status',
                    'priority',
                    'opened_at',
                    'reopened_at',
                    'closed_at',
                    'pic_user_id',
                ])
                ->with(['loanAccount:id,account_no,ao_code,position_date,kolek,dpd,outstanding']);

            // status filter (default open)
            if ($status !== '' && strtolower($status) !== 'all') {
                $q->where('status', $status);
            }

            // filter per PIC (optional)
            if (!empty($pic)) {
                $q->where('pic_user_id', (int)$pic);
            }

            // filter per case id (optional)
            if (!empty($caseOpt)) {
                $ids = collect(explode(',', (string)$caseOpt))
                    ->map(fn($v) => (int)trim($v))
                    ->filter(fn($v) => $v > 0)
                    ->values()
                    ->all();

                if (!empty($ids)) {
                    $q->whereIn('id', $ids);
                }
            }

            // filter posisi tanggal (loan_accounts.position_date)
            if (!empty($date)) {
                $q->whereHas('loanAccount', function ($la) use ($date) {
                    $la->whereDate('position_date', $date);
                });
            }

            // filter AO (loan_accounts.ao_code)
            if (!empty($ao)) {
                $aoNorm = $this->normalizeCode($ao, 6);
                $q->whereHas('loanAccount', function ($la) use ($aoNorm) {
                    $la->where('ao_code', $aoNorm);
                });
            }

            $q->orderBy('id');

            $total = (clone $q)->count();

            if ($total === 0) {
                $this->info('Tidak ada case yang cocok dengan filter.');
                return self::SUCCESS;
            }

            $this->line('=================================================');
            $this->info('Target refresh warning chain');
            $this->line('-------------------------------------------------');
            $this->line("Total kandidat : {$total}");
            $this->line("Filter status  : {$status}");
            $this->line("Filter date    : " . ($date ?: '-'));
            $this->line("Filter ao      : " . (!empty($ao) ? $this->normalizeCode($ao, 6) : '-'));
            $this->line("Filter pic     : " . ($pic ?: '-'));
            $this->line("Case id(s)     : " . ($caseOpt ?: '-'));
            $this->line("Chunk size     : {$chunk}");
            $this->line("Limit          : " . ($limit > 0 ? $limit : 'tanpa batas'));
            $this->line("Dry run        : " . ($dryRun ? 'YA' : 'TIDAK'));
            $this->line('=================================================');

            if (!$force && !$dryRun) {
                if (!$this->confirm('Lanjutkan proses refresh warning chain?', true)) {
                    $this->warn('Dibatalkan.');
                    return self::SUCCESS;
                }
            }

            // =========================
            // 3) Eksekusi batch
            // =========================
            $scheduler = app(CaseScheduler::class);

            // ✅ Tag semua schedule hasil command ini
            if (method_exists($scheduler, 'setSourceSystem')) {
                $scheduler->setSourceSystem('cmd_refresh');
            }

            $processed = 0;
            $success   = 0;
            $failed    = 0;

            $bar = $this->output->createProgressBar($limit > 0 ? min($total, $limit) : $total);
            $bar->start();

            $stop = false;

            $q->chunkById($chunk, function ($cases) use (
                $scheduler, $dryRun, $limit, $sleep,
                &$processed, &$success, &$failed, &$stop, $bar
            ) {
                if ($stop) return false;

                foreach ($cases as $case) {
                    if ($limit > 0 && $processed >= $limit) {
                        $stop = true;
                        break;
                    }

                    try {
                        if ($dryRun) {
                            $processed++;
                            $success++;
                            $bar->advance();
                            continue;
                        }

                        $scheduler->refreshWarningChain($case);

                        $processed++;
                        $success++;
                        $bar->advance();
                    } catch (\Throwable $e) {
                        $processed++;
                        $failed++;
                        $bar->advance();

                        Log::error('[cases:refresh-warning-chain] gagal refresh', [
                            'case_id' => $case->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }

                if ($sleep > 0) {
                    usleep($sleep * 1000);
                }

                if ($stop) return false;
                return true;
            });

            $bar->finish();
            $this->newLine(2);

            $this->info("Selesai. processed={$processed}, success={$success}, failed={$failed}");

            return $failed > 0 ? self::FAILURE : self::SUCCESS;

        } finally {
            optional($lock)->release();
        }
    }

    private function normalizeCode($value, int $pad = 6): ?string
    {
        if ($value === null) return null;

        $v = trim((string)$value);
        if ($v === '') return null;

        if (ctype_digit($v)) {
            return str_pad($v, $pad, '0', STR_PAD_LEFT);
        }

        return $v;
    }
}
