<?php

namespace App\Services\Kpi;

use App\Models\LoanAccount;
use App\Models\MarketingKpiAchievement;
use App\Models\MarketingKpiTarget;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MarketingKpiAchievementService
{
    /**
     * Hitung & simpan achievement untuk 1 target.
     * - OS end: snapshot jika ada, else loan_accounts posisi terakhir di bulan itu
     * - NOA end: snapshot noa_end jika ada, else count loan_accounts posisi terakhir
     * - "Debitur baru": proxy = NOA growth (NOA end now - NOA end prev)
     */
    public function computeForTarget(MarketingKpiTarget $target, bool $forceRecalc = false): MarketingKpiAchievement
    {
        $target->loadMissing('user');

        $user = $target->user;
        if (!$user) {
            throw new \RuntimeException('Target tidak punya user relasi.');
        }

        $period = Carbon::parse($target->period)->startOfMonth();

        // jika sudah ada achievement & tidak force => return
        $existing = MarketingKpiAchievement::query()
            ->where('target_id', $target->id)
            ->first();

        // ✅ bulan berjalan harus selalu boleh update (live berubah-ubah)
        $periodStart = Carbon::parse($target->period)->startOfMonth();
        $isCurrentMonth = $periodStart->equalTo(now()->startOfMonth());

        if ($existing && !$forceRecalc && !$isCurrentMonth) {
            return $existing;
        }

        $now  = $this->resolveMonthEnd($user, $period);
        $prev = $this->resolveMonthEnd($user, $period->copy()->subMonth());

        $osEndNow  = (float)$now['os_end'];
        $osEndPrev = (float)$prev['os_end'];
        $osGrowth  = $osEndNow - $osEndPrev;

        $noaEndNow  = (int)$now['noa_end'];
        $noaEndPrev = (int)$prev['noa_end'];
        $noaGrowth  = $noaEndNow - $noaEndPrev;

        // target KPI
        $targetOs = (float)$target->target_os_growth;
        $targetNoa = (int)$target->target_noa;

        $osAchPct  = $this->safePct($osGrowth, $targetOs);
        $noaAchPct = $this->safePct($noaGrowth, (float)$targetNoa);

        $capOs  = (float)config('kpi.cap_pct.os', 120);
        $capNoa = (float)config('kpi.cap_pct.noa', 120);

        $scoreOs  = $this->clamp($osAchPct, 0, $capOs);
        $scoreNoa = $this->clamp($noaAchPct, 0, $capNoa);

        // bobot
        $wOs  = (int)($target->weight_os ?? 60);
        $wNoa = (int)($target->weight_noa ?? 40);
        $wSum = max(1, $wOs + $wNoa);

        // total score (0..cap)
        $scoreTotal = ($scoreOs * $wOs / $wSum) + ($scoreNoa * $wNoa / $wSum);

        // final jika snapshot tersedia untuk now & prev (lebih aman)
        $periodStart = $period->copy()->startOfMonth();
        $isClosedMonth = $periodStart->lt(now()->startOfMonth());

        // final hanya kalau bulan sudah lewat (tutup)
        $isFinal = $isClosedMonth;

        $payload = [
            'target_id' => $target->id,
            'user_id'   => $user->id,
            'period'    => $period->toDateString(),

            'os_source_now' => $now['os_source'],
            'os_source_prev'=> $prev['os_source'],
            'position_date_now'  => $now['position_date_used'],
            'position_date_prev' => $prev['position_date_used'],
            'is_final' => $isFinal,

            'os_end_now'  => $osEndNow,
            'os_end_prev' => $osEndPrev,
            'os_growth'   => $osGrowth,

            'noa_end_now'  => $noaEndNow,
            'noa_end_prev' => $noaEndPrev,
            'noa_growth'   => $noaGrowth,

            'os_ach_pct'  => $osAchPct,
            'noa_ach_pct' => $noaAchPct,

            'score_os'    => $scoreOs,
            'score_noa'   => $scoreNoa,
            'score_total' => $scoreTotal,
        ];

        return DB::transaction(function () use ($existing, $payload) {
            if ($existing) {
                $existing->update($payload);
                return $existing->fresh();
            }

            return MarketingKpiAchievement::create($payload);
        });
    }

    /**
     * Resolve OS end & NOA end untuk 1 AO 1 periode.
     * Return:
     *  - os_end, noa_end
     *  - os_source snapshot|loan_accounts|none
     *  - position_date_used (date|null)
     */
    public function resolveMonthEnd(User $user, Carbon $period): array
    {
        $aoCode = (string)($user->ao_code ?? '');
        if ($aoCode === '') {
            return [
                'os_end' => 0.0,
                'noa_end' => 0,
                'os_source' => 'none',
                'position_date_used' => null,
            ];
        }

        $period = $period->copy()->startOfMonth();
        $monthStart = $period->toDateString();              // YYYY-MM-01
        $monthEnd   = $period->copy()->endOfMonth()->toDateString();

        $periodStart = $period->copy()->startOfMonth();     // bulan target
        $currStart   = now()->startOfMonth();               // bulan sekarang

        $isClosedMonth = $periodStart->lt($currStart);      // true kalau bulan target sudah lewat

        // =========================
        // Bulan berjalan -> LIVE (loan_accounts posisi terakhir dalam bulan)
        // =========================
        if (!$isClosedMonth) {
            $live = $this->resolveFromLoanAccounts($user, $periodStart);

            // Pastikan source konsisten untuk UI
            return [
                'os_end' => (float)($live['os_end'] ?? 0),
                'noa_end'=> (int)($live['noa_end'] ?? 0),
                'os_source' => 'live',
                'position_date_used' => $live['position_date_used'] ?? null,
            ];
        }

        // =========================
        // 1) SNAPSHOT: loan_account_snapshots_monthly
        // =========================
        $cfg = (array) config('kpi.marketing_snapshot', []);
        $table        = (string)($cfg['table'] ?? 'loan_account_snapshots_monthly');
        $periodCol    = (string)($cfg['period_col'] ?? 'snapshot_month');
        $aoCol        = (string)($cfg['ao_col'] ?? 'ao_code');
        $osCol        = (string)($cfg['os_col'] ?? 'outstanding');
        $accountCol   = (string)($cfg['account_col'] ?? 'account_no');
        $sourcePosCol = (string)($cfg['source_pos_col'] ?? 'source_position_date');
        $noaDistinct  = (bool)($cfg['noa_distinct'] ?? true);

        // Pakai base query sekali
        $baseSnapQ = DB::table($table)
            ->whereDate($periodCol, $monthStart)
            ->where($aoCol, $aoCode);

        // ✅ Snapshot dianggap valid kalau ada row, bukan kalau sum(outstanding) > 0
        $hasSnap = $baseSnapQ->exists();

        if ($hasSnap) {
            $osSnap = (float) (clone $baseSnapQ)->sum($osCol);

            $noaSnap = $noaDistinct
                ? (int) (clone $baseSnapQ)->distinct()->count($accountCol)
                : (int) (clone $baseSnapQ)->count();

            // position date: max(source_position_date) kalau ada, else monthEnd
            $pos = null;
            try {
                $pos = (clone $baseSnapQ)->max($sourcePosCol);
            } catch (\Throwable $e) {
                $pos = null;
            }

            return [
                'os_end' => $osSnap,
                'noa_end'=> $noaSnap,
                'os_source' => 'snapshot',
                'position_date_used' => $pos ? (string)$pos : $monthEnd,
            ];
        }

        // =========================
        // 2) FALLBACK LIVE (loan_accounts) untuk bulan tutup kalau snapshot belum ada
        // =========================
        $lastDate = LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', '>=', $monthStart)
            ->whereDate('position_date', '<=', $monthEnd)
            ->max('position_date');

        if (!$lastDate) {
            return [
                'os_end' => 0.0,
                'noa_end' => 0,
                'os_source' => 'live',
                'position_date_used' => null,
            ];
        }

        $os = (float) LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', $lastDate)
            ->sum('outstanding');

        $noa = (int) LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', $lastDate)
            ->count();

        return [
            'os_end' => $os,
            'noa_end'=> $noa,
            'os_source' => 'live',
            'position_date_used' => (string)$lastDate,
        ];
    }

    private function fallbackNoaFromLoanAccounts(string $aoCode, string $monthStart, string $monthEnd): array
    {
        $lastDate = LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', '>=', $monthStart)
            ->whereDate('position_date', '<=', $monthEnd)
            ->max('position_date');

        if (!$lastDate) return ['noa_end' => 0];

        $noa = (int) LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', $lastDate)
            ->count();

        return ['noa_end' => $noa];
    }

    private function safePct(float $actual, float $target): float
    {
        if ($target <= 0) return 0.0;
        return round(($actual / $target) * 100, 2);
    }

    private function clamp(float $v, float $min, float $max): float
    {
        return max($min, min($max, $v));
    }

    protected function resolveFromLoanAccounts(User $user, Carbon $periodStart): array
    {
        $aoCode = (string)($user->ao_code ?? '');
        if ($aoCode === '') {
            return [
                'os_end' => 0.0, 'noa_end' => 0,
                'os_source' => 'loan_accounts',
                'position_date_used' => null,
            ];
        }

        $monthStart = $periodStart->toDateString();
        $monthEnd   = $periodStart->copy()->endOfMonth()->toDateString();

        $lastDate = LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', '>=', $monthStart)
            ->whereDate('position_date', '<=', $monthEnd)
            ->max('position_date');

        if (!$lastDate) {
            return [
                'os_end' => 0.0, 'noa_end' => 0,
                'os_source' => 'loan_accounts',
                'position_date_used' => null,
            ];
        }

        $os = (float) LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', $lastDate)
            ->sum('outstanding');

        $noa = (int) LoanAccount::query()
            ->where('ao_code', $aoCode)
            ->whereDate('position_date', $lastDate)
            ->count();

        return [
            'os_end' => $os,
            'noa_end'=> $noa,
            'os_source' => 'loan_accounts',
            'position_date_used' => (string)$lastDate,
        ];
    }

}
