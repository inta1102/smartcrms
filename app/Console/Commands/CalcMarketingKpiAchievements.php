<?php

namespace App\Console\Commands;

use App\Models\MarketingKpiTarget;
use App\Services\Kpi\MarketingKpiAchievementService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalcMarketingKpiAchievements extends Command
{
    protected $signature = 'kpi:calc-marketing-achievements
        {--period= : Period YYYY-MM (mis: 2026-01)}
        {--force : Force recalc walau sudah ada}
        {--user_id= : (optional) hitung untuk user_id tertentu}';

    protected $description = 'Hitung & simpan pencapaian KPI Marketing dari target APPROVED (atau semua target jika diperlukan).';

    public function handle(MarketingKpiAchievementService $svc): int
    {
        $periodOpt = (string)($this->option('period') ?? '');
        $force = (bool)$this->option('force');
        $userId = $this->option('user_id');

        $period = null;
        if ($periodOpt !== '' && preg_match('/^\d{4}-\d{2}$/', $periodOpt)) {
            $period = Carbon::createFromFormat('Y-m', $periodOpt)->startOfMonth()->toDateString();
        }

        $q = MarketingKpiTarget::query()->with('user');

        // Praktik terbaik: hitung yang APPROVED saja (karena target final sudah disetujui)
        $q->where('status', MarketingKpiTarget::STATUS_APPROVED);

        if ($period) {
            $q->whereDate('period', $period);
        }

        if ($userId) {
            $q->where('user_id', (int)$userId);
        }

        $targets = $q->get();
        if ($targets->isEmpty()) {
            $this->info('Tidak ada target yang cocok.');
            return self::SUCCESS;
        }

        $this->info('Menghitung: '.$targets->count().' target...');
        $ok = 0; $fail = 0;

        foreach ($targets as $t) {
            try {
                $svc->computeForTarget($t, $force);
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                $this->error("FAIL target_id={$t->id} user_id={$t->user_id} : ".$e->getMessage());
            }
        }

        $this->info("DONE. OK={$ok}, FAIL={$fail}");
        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
