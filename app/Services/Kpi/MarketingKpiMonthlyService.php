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

    public function recalcAnyUserAndPeriod(int $userId, Carbon $period): void
    {
        $u = User::find($userId);
        if (!$u) return;

        $role = $this->roleValueFromUser($u);
        $periodYmd = $period->copy()->startOfMonth()->toDateString();

        // calcMode standar (yang kamu pakai di builder KBL/KSLR)
        $calcMode = $period->copy()->startOfMonth()->equalTo(now()->startOfMonth())
            ? 'realtime'
            : 'eom';

        // =========================================
        // 1) STAFF foundation
        // =========================================
        if (in_array($role, ['AO','RO','SO','FE','BE'], true)) {
            $this->recalcStaff($u, $periodYmd);   // <- fungsi kamu yang existing
            return;
        }

        // =========================================
        // 2) LEADERSHIP routing (opsi B)
        // =========================================

        // ---- TLUM (adapter) ----
        if ($role === 'TLUM') {
            app(\App\Services\Kpi\TlumMarketingMonthlyBuilder::class)
                ->build((int)$u->id, $periodYmd);
            return;
        }

        // ---- KSLR ----
        if ($role === 'KSLR') {
            app(\App\Services\Kpi\KslrMonthlyBuilder::class)
                ->build(
                    (int)$u->id,
                    $periodYmd,
                    $calcMode,
                    app(\App\Services\Org\OrgScopeService::class) // sesuaikan namespace
                );
            return;
        }

       if ($role === 'KSBE') {
            app(\App\Services\Kpi\KsbeKpiMonthlyService::class)
                ->buildAndStore(
                    (int)$u->id,
                    \Carbon\Carbon::parse($periodYmd)->format('Y-m')
                );
            return;
        }

        // ---- KBL ----
        if ($role === 'KBL') {
            // paling aman: kalau kosong = GLOBAL (sesuai komentar di KblMonthlyBuilder kamu)
            $scopeAoCodes = [];

            app(\App\Services\Kpi\KblMonthlyBuilder::class)
                ->build(
                    (int)$u->id,
                    $periodYmd,
                    $scopeAoCodes,
                    null
                );
            return;
        }

        // ---- Role leadership lain (TLRO/TLFE/TLBE/KSFE/KSBE...) ----
        // fallback ke dispatcher generik kamu (yang pakai resolveLeadershipBuilderClass + runLeadershipBuilderIfAny)
        $builderClass = $this->resolveLeadershipBuilderClass($role);
        if ($builderClass) {
            $this->runLeadershipBuilderIfAny($builderClass, $u, $period);
        }
    }

    private function recalcStaff(User $u, string $periodYmd): void
    {
        $role   = $this->roleValueFromUser($u);
        $userId = (int) $u->id;

        // helper
        $periodYm = \Carbon\Carbon::parse($periodYmd)->format('Y-m');
        $calcMode = \Carbon\Carbon::parse($periodYmd)->startOfMonth()->equalTo(now()->startOfMonth())
            ? 'realtime'
            : 'eom';

        switch ($role) {

            // =====================
            // AO ✅ FIX: pakai builder/service AO baru
            // =====================
            case 'AO':
                app(\App\Services\Kpi\AoKpiMonthlyService::class)
                    ->buildForPeriod($periodYmd, $userId);
                return;

            case 'RO':
                app(\App\Services\Kpi\RoKpiMonthlyBuilder::class)
                    ->buildAndStoreForAo(
                        $periodYmd,
                        $u->ao_code,
                        $calcMode
                    );
                return;
                            
            case 'SO':
                app(\App\Services\Kpi\SoKpiMonthlyService::class)
                    ->buildForPeriod($periodYmd, $userId);
                return;

            // =====================
            // FE ✅ FIX
            // =====================
            case 'FE':
                // FeKpiMonthlyBuilder signature: build(periodYmd, mode?, actorId?)
                app(\App\Services\Kpi\FeKpiMonthlyBuilder::class)
                    ->build($periodYmd, $calcMode, auth()->id());
                return;

            case 'BE':
                app(\App\Services\Kpi\BeKpiMonthlyService::class)
                    ->persistFromBuild($periodYm, $u);
                return;
        }
    }

    // resolveLeadershipBuilderClass + runLeadershipBuilderIfAny tetap dipakai

    private function roleValueFromUser(User $user): string
    {
        // kandidat field yang mungkin dipakai di projectmu
        $candidates = [
            $user->roleValue() ?? null, // kalau method ada
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
        ];

        // ===== KASI family (KS*) =====
        $ksMap = [
            'KSLR' => \App\Services\Kpi\KslrMonthlyBuilder::class,
            'KSFE' => \App\Services\Kpi\KsfeLeadershipBuilder::class,
            'KSBE' => \App\Services\Kpi\KsbeKpiMonthlyService::class,
        ];

        // ===== KABAG family =====
        $kabagMap = [
            'KBL'  => \App\Services\Kpi\KblMonthlyBuilder::class,
        ];

        if (isset($tlMap[$role])) return $tlMap[$role];
        if (isset($ksMap[$role])) return $ksMap[$role];
        if (isset($kabagMap[$role])) return $kabagMap[$role];

        return null; // unknown role -> no builder
    }

    private function runLeadershipBuilderIfAny(string $builderClass, \App\Models\User $user, \Carbon\Carbon $period): void
    {
        // ✅ Guard: builderClass null/empty (misal TLUM kita set null)
        if (!$builderClass) {
            \Log::info('KPI Recalc skip (no builderClass)', [
                'user_id' => (int)$user->id,
                'role'    => $this->roleValueFromUser($user),
                'period'  => $period->toDateString(),
            ]);
            return;
        }

        // ✅ Guard: class belum ada (kasus TLUM paling sering)
        if (!class_exists($builderClass)) {
            \Log::warning('KPI Recalc skip (builder class not found)', [
                'builder' => $builderClass,
                'user_id' => (int)$user->id,
                'role'    => $this->roleValueFromUser($user),
                'period'  => $period->toDateString(),
            ]);
            return;
        }

        $svc = app($builderClass);

        $periodObj = $period->copy()->startOfMonth();
        $periodYmd = $periodObj->toDateString();          // 2026-03-01
        $periodYm  = $periodObj->format('Y-m');           // 2026-03
        $userId    = (int) $user->id;

        $calcMode = $period->equalTo(now()->startOfMonth()) ? 'realtime' : 'eom';

        // paling aman untuk KBL: kosong = GLOBAL
        $scopeAoCodes = [];

        $calls = [
            ['build', [$userId, $periodYmd, $calcMode, app(\App\Services\Org\OrgScopeService::class)]],
            ['build', [$userId, $periodYmd, $scopeAoCodes, null]],
            ['build', [$userId, $periodYmd]],
            ['build', [$userId, $periodObj]],

            ['buildForLeader', [$user, $periodObj]],
            ['buildForLeader', [$user, $periodYmd]],
            ['buildForLeader', [$userId, $periodObj]],
            ['buildForLeader', [$userId, $periodYmd]],

            ['buildForPeriod', [$periodYm, $user]],
            ['buildForPeriod', [$periodYm, $userId]],
            ['buildForPeriod', [$periodYm, null]],
            ['buildForPeriod', [$periodYm]],

            ['buildForPeriod', [$periodYmd, $user]],
            ['buildForPeriod', [$periodYmd, $userId]],
            ['buildForPeriod', [$periodYmd]],

            ['recalc', [$userId, $periodObj]],
            ['recalc', [$userId, $periodYmd]],
            ['handle', [$userId, $periodYmd]],
        ];

        $lastErr = null;

        foreach ($calls as [$method, $args]) {
            if (!method_exists($svc, $method)) continue;

            try {
                $svc->{$method}(...$args);
                return; // ✅ sukses 1 signature, stop
            } catch (\Throwable $e) {
                $lastErr = $e;
                continue;
            }
        }

        // optional: log last error
        if ($lastErr) {
            \Log::warning('KPI Recalc builder failed all signatures', [
                'builder' => $builderClass,
                'user_id' => $userId,
                'role'    => $this->roleValueFromUser($user),
                'period'  => $periodYmd,
                'err'     => $lastErr->getMessage(),
            ]);
        }
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