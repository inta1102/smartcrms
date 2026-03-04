<?php

namespace App\Services\Kpi;

use App\Models\MarketingKpiMonthly;
use App\Models\MarketingKpiTarget;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class MarketingKpiMonthlyService
{
    /**
     * NOA mode:
     * - 'accounts' => COUNT(*)
     * - 'cif'      => COUNT(DISTINCT cif)
     */
    public function __construct(
        protected string $noaMode = 'accounts'
    ) {}

    public function recalcForUserAndPeriod(int $userId, string|Carbon $period): MarketingKpiMonthly
    {
        $period = Carbon::parse($period)->startOfMonth();

        $user = User::query()->findOrFail($userId);
        $aoCode = (string) ($user->ao_code ?? '');

        // Ambil target periode tsb
        $target = MarketingKpiTarget::query()
            ->whereDate('period', $period->toDateString())
            ->where('user_id', $userId)
            ->first();

        $targetOs  = (int) ($target?->target_os_growth ?? 0);
        $targetNoa = (int) ($target?->target_noa ?? 0);
        $wOs       = (int) ($target?->weight_os ?? 60);
        $wNoa      = (int) ($target?->weight_noa ?? 40);

        $prevPeriod = $period->copy()->subMonth()->startOfMonth();

        // =========================================
        // 1) DATA NOW (lebih dulu, supaya $osNow tersedia)
        // =========================================
        $isCurrentMonth = $period->isSameMonth(now());

        if ($isCurrentMonth) {
            [$osNow, $noaNow] = $this->fromLiveLoanAccounts($aoCode);
            $srcNow  = 'live';
            $posNow  = now()->toDateString();
            $isFinal = false;
        } else {
            [$osNow, $noaNow] = $this->fromSnapshot($aoCode, $period);
            $srcNow  = 'snapshot';
            $posNow  = $period->toDateString(); // konsisten: YYYY-MM-01
            $isFinal = true;
        }

        // =========================================
        // 2) DATA PREV (snapshot) + deteksi missing
        // =========================================
        [$osPrev, $noaPrev] = $this->fromSnapshot($aoCode, $prevPeriod);

        $prevCnt = (int) DB::table('loan_account_snapshots_monthly')
            ->where('snapshot_month', $prevPeriod->toDateString())
            ->where('ao_code', $aoCode)
            ->count();

        $prevMissing = ($prevCnt === 0);

        if ($prevMissing) {
            // ✅ opsi aman: jangan bikin growth meledak karena prev snapshot kosong
            // artinya growth dianggap 0 untuk bulan tsb
            $osPrev  = $osNow;
            $noaPrev = $noaNow;
        }

        // =========================================
        // 3) GROWTH, ACH, SCORE
        // =========================================
        $osGrowth  = (int) ($osNow - $osPrev);
        $noaGrowth = (int) ($noaNow - $noaPrev);

        $osAch  = $targetOs  > 0 ? round(($osGrowth  / $targetOs)  * 100, 2) : 0.00;
        $noaAch = $targetNoa > 0 ? round(($noaGrowth / $targetNoa) * 100, 2) : 0.00;

        $scoreOs    = round(min($osAch, 120) * ($wOs / 100), 2);
        $scoreNoa   = round(min($noaAch, 120) * ($wNoa / 100), 2);
        $scoreTotal = round($scoreOs + $scoreNoa, 2);

        return DB::transaction(function () use (
            $userId, $period, $target, $aoCode,
            $targetOs, $targetNoa, $wOs, $wNoa,
            $osNow, $osPrev, $osGrowth,
            $noaNow, $noaPrev, $noaGrowth,
            $srcNow, $posNow, $isFinal,
            $osAch, $noaAch, $scoreOs, $scoreNoa, $scoreTotal,
            $prevPeriod
        ) {
            $m = MarketingKpiMonthly::query()->firstOrNew([
                'user_id' => $userId,
                'period'  => $period->toDateString(),
            ]);

            $m->target_id = $target?->id;
            $m->ao_code   = $aoCode ?: null;

            $m->target_os_growth = $targetOs;
            $m->target_noa       = $targetNoa;
            $m->weight_os        = $wOs;
            $m->weight_noa       = $wNoa;

            $m->os_end_now  = (int) $osNow;
            $m->os_end_prev = (int) $osPrev;
            $m->os_growth   = (int) $osGrowth;

            $m->noa_end_now  = (int) $noaNow;
            $m->noa_end_prev = (int) $noaPrev;
            $m->noa_growth   = (int) $noaGrowth;

            $m->os_source_now  = $srcNow;
            $m->os_source_prev = 'snapshot';
            $m->position_date_now  = $posNow;
            $m->position_date_prev = $prevPeriod->toDateString();

            $m->os_ach_pct  = $osAch;
            $m->noa_ach_pct = $noaAch;
            $m->score_os    = $scoreOs;
            $m->score_noa   = $scoreNoa;
            $m->score_total = $scoreTotal;

            $m->is_final    = $isFinal;
            $m->computed_at = now();
            $m->save();

            return $m;
        });
    }

    public function recalcAnyUserAndPeriod(int $userId, string|Carbon $period): void
    {
        $period = Carbon::parse($period)->startOfMonth();

        $user = User::query()->findOrFail($userId);

        // Di project kamu: role kadang di level, kadang di role/roleValue
        $role = $this->roleValueFromUser($user);

        
        // =========================
        // STAFF: AO/RO/SO/FE/BE
        // =========================
        if (in_array($role, ['AO','RO','SO','FE','BE'], true)) {
            // method lama kamu tetap dipakai (return MarketingKpiMonthly)
            $this->recalcForUserAndPeriod($userId, $period);
            return;
        }

        // =========================
        // LEADERSHIP (TL / KASI / KABAG)
        // =========================
        

        $builderClass = $this->resolveLeadershipBuilderClass($role);
        if ($builderClass) {
            $this->runLeadershipBuilderIfAny($builderClass, $user, $period);
        }
        return;

        // Default: no-op (biar aman)
    }
    
    private function roleValueFromUser(User $user): string
    {
        // kandidat field yang mungkin dipakai di projectmu
        $candidates = [
            // $user->role_value ?? null,
            $user->roleValue() ?? null, // kalau method ada
            // $user->role ?? null,
            $user->level ?? null,
        ];

        foreach ($candidates as $v) {
            if ($v === null) continue;

            // PHP backed enum: punya ->value
            if (is_object($v) && property_exists($v, 'value')) {
                $s = (string) $v->value;
                if ($s !== '') return strtoupper($s);
            }

            // UnitEnum (enum biasa) punya name
            if ($v instanceof \UnitEnum) {
                $s = (string) $v->name;
                if ($s !== '') return strtoupper($s);
            }

            // string biasa
            if (is_string($v)) {
                $s = trim($v);
                if ($s !== '') return strtoupper($s);
            }
        }

        return '';
    }

    private function resolveLeadershipBuilderClass(string $role): ?string
    {
        $role = strtoupper($role);

        // ===== TL family =====
        $tlMap = [
            'TLRO' => \App\Services\Kpi\TlroLeadershipBuilder::class,
            'TLUM' => \App\Services\Kpi\TlumMarketingMonthlyBuilder::class,
            'TLFE' => \App\Services\Kpi\TlfeLeadershipBuilder::class,
            'TLBE' => \App\Services\Kpi\TlBeMonthlyService::class,
            // 'TLSO' => \App\Services\Kpi\Leadership\TlsoMarketingMonthlyBuilder::class,
            // tambahin kalau ada lagi
        ];

        // ===== KASI family (KS*) =====
        $ksMap = [
            'KSLR' => \App\Services\Kpi\KslrMonthlyBuilder::class,
            'KSFE' => \App\Services\Kpi\KsfeLeadershipBuilder::class,
            'KSBE' => \App\Services\Kpi\KsbeKpiMonthlyService::class,
            // 'KSLU' => \App\Services\Kpi\Leadership\KsluMarketingMonthlyBuilder::class,
            // 'KSSO' => \App\Services\Kpi\Leadership\KssoMarketingMonthlyBuilder::class,
            // tambahin kalau ada lagi
        ];

        // ===== KABAG family =====
        $kabagMap = [
            'KBL'  => \App\Services\Kpi\KblMonthlyBuilder::class,
            // contoh kalau ada: 'KBO' => ..., dst
        ];

        if (isset($tlMap[$role])) return $tlMap[$role];
        if (isset($ksMap[$role])) return $ksMap[$role];
        if (isset($kabagMap[$role])) return $kabagMap[$role];

        return null; // unknown role -> no builder
    }

    
    private function runLeadershipBuilderIfAny(string $builderClass, User $user, Carbon $period): void
    {
        if (!class_exists($builderClass)) {
            Log::warning('KPI Recalc builder class not found', [
                'builder_class' => $builderClass,
                'user_id' => $user->id,
                'role' => $this->roleValueFromUser($user),
            ]);
            return;
        }

        $builder = app($builderClass);

        $calls = [
            // method => args
            ['buildForLeader', [$user, $period]],
            ['buildForLeader', [$user->id, $period]],
            ['build',          [$user, $period]],
            ['build',          [$user->id, $period]],
            ['recalcForLeader',[$user, $period]],
            ['recalcForLeader',[$user->id, $period]],
            ['recalcAnyUserAndPeriod', [$user->id, $period]],
            ['recalcForUserAndPeriod', [$user->id, $period]],
            ['handle',         [$user, $period]],
            ['handle',         [$user->id, $period]],
        ];

        foreach ($calls as [$method, $args]) {
            if (method_exists($builder, $method)) {
                Log::info('KPI Recalc builder call', [
                    'builder_class' => $builderClass,
                    'method' => $method,
                    'user_id' => $user->id,
                    'role' => $this->roleValueFromUser($user),
                    'period' => $period->toDateString(),
                ]);

                $builder->{$method}(...$args);
                return;
            }
        }

        Log::warning('KPI Recalc builder has no compatible method', [
            'builder_class' => $builderClass,
            'user_id' => $user->id,
            'role' => $this->roleValueFromUser($user),
        ]);
    }

    /**
     * Aggregate dari loan_account_snapshots_monthly (detail loan).
     * snapshot_month = YYYY-MM-01
     */
    protected function fromSnapshot(string $aoCode, Carbon $monthStart): array
    {
        if (!$aoCode) return [0, 0];

        $base = DB::table('loan_account_snapshots_monthly')
            ->where('snapshot_month', $monthStart->toDateString())
            ->where('ao_code', $aoCode);

        $os = (float) (clone $base)->sum('outstanding');

        $noa = $this->noaMode === 'cif'
            ? (int) (clone $base)->distinct('cif')->count('cif')
            : (int) (clone $base)->count();


        return [(int) round($os), $noa];
    }

    /**
     * Aggregate dari loan_accounts (live).
     */
    protected function fromLiveLoanAccounts(string $aoCode): array
    {
        if (!$aoCode) return [0, 0];

        $os = (float) DB::table('loan_accounts')
            ->where('ao_code', $aoCode)
            ->sum('outstanding');

        if ($this->noaMode === 'cif') {
            $noa = (int) DB::table('loan_accounts')
                ->where('ao_code', $aoCode)
                ->distinct('cif')
                ->count('cif');
        } else {
            $noa = (int) DB::table('loan_accounts')
                ->where('ao_code', $aoCode)
                ->count();
        }

        return [(int) round($os), $noa];
    }
}
