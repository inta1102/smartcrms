<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FeKpiMonthlyService
{
    // ====== Weights KPI FE (sesuai slide) ======
    public array $weights = [
        'os_turun' => 0.40,
        'migrasi'  => 0.40,
        'penalty'  => 0.20,
    ];

    // ====== Mode ======
    public function resolveMode(Carbon $period): string
    {
        return $period->greaterThanOrEqualTo(now()->startOfMonth()) ? 'realtime' : 'eom';
    }

    // ====== Scoring helper: KPI yang berbasis pencapaian % ======
    // 0-24=1, 25-49=2, 50-74=3, 75-99=4, 100=5, >100=6
    public function scoreFromAchievement(float $ach): float
    {
        if ($ach > 100) return 6.0;
        if ($ach >= 100) return 5.0;
        if ($ach >= 75)  return 4.0;
        if ($ach >= 50)  return 3.0;
        if ($ach >= 25)  return 2.0;
        return 1.0;
    }

    // ====== Scoring Migrasi NPL (reverse) sesuai slide ======
    // >1.5=1, 1-1.49=2, 0.5-0.99=3, 0.3-0.49=4, 0.01-0.3=5, 0=6
    public function scoreMigrasiFromPct(float $migrasiPct): float
    {
        $p = max(0.0, $migrasiPct);

        if ($p <= 0.0)    return 6.0;
        if ($p <= 0.30)   return 5.0;
        if ($p <= 0.49)   return 4.0;
        if ($p <= 0.99)   return 3.0;
        if ($p <= 1.49)   return 2.0;
        return 1.0;
    }

    public function pct(float $actual, float $target): float
    {
        if ($target <= 0) return 0.0;
        return round(($actual / $target) * 100.0, 2);
    }

    // reverse achievement: makin kecil actual makin bagus (dibatasi biar display masuk akal)
    public function achReverse(float $actual, float $target): float
    {
        if ($target <= 0) return 0.0;
        if ($actual <= 0) return 100.0;
        $v = round(($target / $actual) * 100.0, 2);
        return min($v, 200.0);
    }

    private function latestLoanPositionDate(): ?string
    {
        // sesuaikan nama kolom & table kamu
        return DB::table('loan_accounts')->max('position_date'); // 'Y-m-d' atau null
    }

    // =========================================================
    // MAIN: build FE KPI for period (YTD Accumulation)
    // Target YTD  : dari kpi_fe_targets
    // Actual  YTD : dari kpi_fe_monthlies
    // =========================================================
    public function buildForPeriod(string $periodYm, $leaderUser = null): array
    {
        $period     = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        $periodDate = $period->toDateString();

        $mode = $this->resolveMode($period);

        // =========================
        // YTD window
        // =========================
        $endMonth   = Carbon::parse($periodDate)->startOfMonth(); // contoh: 2026-02-01
        $startMonth = $endMonth->copy()->startOfYear();           // contoh: 2026-01-01

        $isEndMonthCurrent = $endMonth->equalTo(now()->startOfMonth());
        $endMonthMode      = $isEndMonthCurrent ? 'realtime' : 'eom';

        $startYtd = $startMonth->toDateString();

        // default EOM untuk label
        $asOfDate = $endMonth->copy()->endOfMonth()->toDateString();

        // realtime => ambil last position_date loan_accounts, tapi jangan lewat bulan yg dipilih
        if ($endMonthMode === 'realtime') {
            $latest = $this->latestLoanPositionDate(); // ex: 2026-02-20
            if ($latest) {
                $latestC  = Carbon::parse($latest)->toDateString();
                $monthEnd = $endMonth->copy()->endOfMonth()->toDateString();
                $asOfDate = min($latestC, $monthEnd);
            }
        }

        // ✅ endYtd untuk LABEL / header akumulasi
        $endYtd = $asOfDate;

        // =========================
        // Leader & Scope
        // =========================
        $leader = $leaderUser ?: auth()->user();
        $scopeUserIds = $this->scopeFeUserIdsForLeader($leader, $period);

       

        // =====================================================
        // ACTUAL AGG (YTD): dari kpi_fe_monthlies
        // Rule:
        // - month < endMonth  => calc_mode = eom
        // - month = endMonth  => calc_mode = endMonthMode
        // =====================================================
        $actualAgg = DB::table('kpi_fe_monthlies as k')
            ->whereBetween('k.period', [$startMonth->toDateString(), $endMonth->toDateString()])
            ->where(function ($w) use ($endMonth, $endMonthMode) {
                $w->where(function ($q) use ($endMonth) {
                    $q->where('k.period', '<', $endMonth->toDateString())
                    ->where('k.calc_mode', '=', 'eom');
                })->orWhere(function ($q) use ($endMonth, $endMonthMode) {
                    $q->where('k.period', '=', $endMonth->toDateString())
                    ->where('k.calc_mode', '=', $endMonthMode);
                });
            })
            ->groupBy('k.fe_user_id')
            ->selectRaw("
                k.fe_user_id,

                SUM(COALESCE(k.os_kol2_awal,0))          as os_kol2_awal_sum,
                SUM(COALESCE(k.os_kol2_akhir,0))         as os_kol2_akhir_sum,

                SUM(COALESCE(k.os_kol2_turun_total,0))   as os_kol2_turun_total_sum,
                SUM(COALESCE(k.os_kol2_turun_murni,0))   as os_kol2_turun_murni_sum,
                SUM(COALESCE(k.os_kol2_turun_migrasi,0)) as os_kol2_turun_migrasi_sum,

                SUM(COALESCE(k.migrasi_npl_os,0))        as migrasi_npl_os_sum,
                SUM(COALESCE(k.penalty_paid_total,0))    as penalty_paid_total_sum,

                MIN(COALESCE(k.baseline_ok,0))           as baseline_ok
            ");

        // =====================================================
        // TARGET AGG (YTD): dari kpi_fe_targets (INI FIX UTAMA)
        // - target rupiah => SUM
        // - target migrasi => weighted by os_awal (pakai actualAgg os_awal_sum)
        //   (kalau os_awal=0 => fallback AVG)
        // =====================================================
        $targetAgg = DB::table('kpi_fe_targets as t')
            ->whereBetween('t.period', [$startMonth->toDateString(), $endMonth->toDateString()])
            ->groupBy('t.fe_user_id')
            ->selectRaw("
                t.fe_user_id,
                SUM(COALESCE(t.target_os_turun_kol2,0))  as target_os_turun_acc,
                SUM(COALESCE(t.target_penalty_paid,0))   as target_penalty_acc,
                AVG(COALESCE(t.target_migrasi_npl_pct,0.3)) as target_migrasi_avg
            ");

        // =========================
        // FE Users base query
        // =========================
        $feUsersQ = DB::table('users as u')
            ->whereRaw("UPPER(TRIM(u.level)) = 'FE'")
            ->whereNotNull('u.ao_code')
            ->whereRaw("TRIM(u.ao_code) <> ''")
            ->orderBy('u.name');

        // apply scope only if not empty
        if (!empty($scopeUserIds)) {
            $feUsersQ->whereIn('u.id', $scopeUserIds);
        }

        // =========================
        // Join users + actualAgg + targetAgg
        // =========================
        $rows = $feUsersQ
            ->leftJoinSub($actualAgg, 'a', function ($j) {
                $j->on('a.fe_user_id', '=', 'u.id');
            })
            ->leftJoinSub($targetAgg, 't', function ($j) {
                $j->on('t.fe_user_id', '=', 'u.id');
            })
            ->selectRaw("
                u.id as user_id, u.name, u.level, u.ao_code,

                COALESCE(a.os_kol2_awal_sum,0)            as os_kol2_awal,
                COALESCE(a.os_kol2_akhir_sum,0)           as os_kol2_akhir,

                COALESCE(a.os_kol2_turun_total_sum,0)     as os_kol2_turun_total,
                COALESCE(a.os_kol2_turun_murni_sum,0)     as os_kol2_turun_murni,
                COALESCE(a.os_kol2_turun_migrasi_sum,0)   as os_kol2_turun_migrasi,

                COALESCE(a.migrasi_npl_os_sum,0)          as migrasi_npl_os,
                COALESCE(a.penalty_paid_total_sum,0)      as penalty_paid_total,

                COALESCE(t.target_os_turun_acc,0)         as target_os_turun_kol2,
                COALESCE(t.target_penalty_acc,0)          as target_penalty_paid,
                COALESCE(t.target_migrasi_avg,0.3)        as target_migrasi_npl_pct,

                COALESCE(a.baseline_ok,0)                 as baseline_ok
            ")
            ->get();

        

        // =========================
        // Map => compute KPI YTD
        // =========================
        $items = $rows->map(function ($r) use ($mode) {

            $osAwalSum    = (float)($r->os_kol2_awal ?? 0);
            $osTurunMurni = (float)($r->os_kol2_turun_murni ?? 0);
            $osTurunTotal = (float)($r->os_kol2_turun_total ?? 0);
            $osTurunMigr  = (float)($r->os_kol2_turun_migrasi ?? 0);

            $penaltyTotal = (float)($r->penalty_paid_total ?? 0);

            $migrasiOsSum = (float)($r->migrasi_npl_os ?? 0);
            $migrasiPctYtd = ($osAwalSum > 0)
                ? round(($migrasiOsSum / $osAwalSum) * 100.0, 4)
                : 0.0;

            $targetOsTurunRp = (float)($r->target_os_turun_kol2 ?? 0);
            $targetPenalty   = (float)($r->target_penalty_paid ?? 0);
            $targetMigrasi   = (float)($r->target_migrasi_npl_pct ?? 0.3000);

            $osTurunPctInfo = ($osAwalSum > 0)
                ? round(($osTurunMurni / $osAwalSum) * 100.0, 4)
                : 0.0;

            $achOsTurun = $this->pct($osTurunMurni, $targetOsTurunRp);
            $achPenalty = $this->pct($penaltyTotal, $targetPenalty);
            $achMigrasi = $this->achReverse($migrasiPctYtd, $targetMigrasi);

            $scoreOsTurun = $this->scoreFromAchievement($achOsTurun);
            $scorePenalty = $this->scoreFromAchievement($achPenalty);
            $scoreMigrasi = $this->scoreMigrasiFromPct($migrasiPctYtd);

            $piOsTurun = round($scoreOsTurun * ($this->weights['os_turun'] ?? 0), 2);
            $piMigrasi = round($scoreMigrasi * ($this->weights['migrasi'] ?? 0), 2);
            $piPenalty = round($scorePenalty * ($this->weights['penalty'] ?? 0), 2);

            $totalPi = round($piOsTurun + $piMigrasi + $piPenalty, 2);

            return (object) array_merge((array)$r, [
                'mode' => $mode,

                // alias utk blade
                'target_nett_os_down'        => $targetOsTurunRp,
                'nett_os_down'               => $osTurunMurni,
                'nett_os_down_total'         => $osTurunTotal,
                'nett_os_down_migrasi'       => $osTurunMigr,
                'nett_os_down_pct_info'      => $osTurunPctInfo,

                'ach_nett_os_down'           => $achOsTurun,
                'score_nett_os_down'         => $scoreOsTurun,
                'pi_nett_os_down'            => $piOsTurun,

                'target_npl_migration_pct'   => $targetMigrasi,
                'npl_migration_pct'          => $migrasiPctYtd,
                'ach_npl_migration'          => $achMigrasi,
                'score_npl_migration'        => $scoreMigrasi,
                'pi_npl_migration'           => $piMigrasi,

                'target_penalty'             => $targetPenalty,
                'penalty_actual'             => $penaltyTotal,
                'ach_penalty'                => $achPenalty,
                'score_penalty'              => $scorePenalty,
                'pi_penalty'                 => $piPenalty,

                'pi_total'                   => $totalPi,
            ]);
        });

        

        // $asOfDate  = $period->copy()->endOfMonth()->toDateString();
        $tlFeRecap = $this->buildTlFeRecap($items, $asOfDate, $leaderUser);

        return [
            'period'    => $period,
            'mode'      => $mode,
            'weights'   => $this->weights,
            'items'     => $items,
            'tlFeRecap' => $tlFeRecap,
            'startYtd'  => $startYtd,
            'endYtd'    => $endYtd,
            'asOfDate'  => $asOfDate,
        ];
    }

    // =========================================================
    // ROLE NORMALIZER
    // =========================================================
    private function leaderRoleValue($user): string
    {
        if (!$user) return 'UNKNOWN';

        $role = '';

        // 1) ambil dari roleValue() kalau ada
        if (method_exists($user, 'roleValue')) {
            $role = (string)($user->roleValue() ?? '');
        }

        // 2) fallback ke level
        if (trim($role) === '') {
            $role = (string)($user->level ?? '');
        }

        // 3) normalize: uppercase, trim, hapus spasi
        $role = strtoupper(trim($role));
        $role = preg_replace('/\s+/', '', $role); // lebih aman dari str_replace(' ','')

        // 4) role alias -> role kanonik
        //    (sesuaikan kalau kamu punya variasi lain)
        $map = [
            // Kabag Lending
            'KBL'          => 'KBL',
            'KABAG'        => 'KBL',
            'KABAGLENDING' => 'KBL',
            'KABAGLEND'    => 'KBL',

            // Team Leader FE
            'TLFE'         => 'TLFE',
            'TL'           => 'TLFE',
            'TEAMLEADER'   => 'TLFE',
            'LEADER'       => 'TLFE',

            // Kasi FE / Lending (kalau memang kamu pakai)
            'KSFE'         => 'KSFE',
            'KASI'         => 'KSFE', // opsional
            'KASIFE'       => 'KSFE', // opsional
            'KASIlending'  => 'KSFE', // kalau ada format aneh, mending rapihin di data
        ];

        return $map[$role] ?? ($role !== '' ? $role : 'UNKNOWN');
    }
    
    /**
     * ✅ FIX UTAMA:
     * Scope FE hanya diterapkan untuk role FE-hierarchy.
     * Role lain (misal KSLR, TLRO, dll) tidak boleh “memaksa” whereIn id yang bukan FE.
     */
    private function scopeFeUserIdsForLeader($leader, Carbon $period): array
    {
        $leaderId   = (int)($leader?->id ?? 0);
        $leaderRole = $this->leaderRoleValue($leader);

        if ($leaderId <= 0) return [];

        // ✅ Global visibility roles => NO FILTER
        if (in_array($leaderRole, ['KBL','KBO','DIR','KOM','ADMIN','SUPERADMIN'], true)) {
            return [];
        }

        // ✅ FE lihat dirinya sendiri
        if ($leaderRole === 'FE') {
            return [$leaderId];
        }

        // ✅ Hierarchy-based (TLFE/KSFE/KSL*/KSBE etc)
        $start = $period->copy()->startOfMonth()->toDateString();
        $end   = $period->copy()->endOfMonth()->toDateString();

        $aliases = match ($leaderRole) {
            'TLFE' => ['tlfe','tl','teamleader','leader'],
            'KSFE' => ['ksfe','kasi','kasilending'],
            'KSLU' => ['kslu','kasi','kasilending'],
            'KSLR' => ['kslr','kasi','kasilending'],
            'KSBE' => ['ksbe','kasi','kasilending'],
            default => [strtolower($leaderRole)],
        };

        return DB::table('org_assignments')
            ->where('leader_id', $leaderId)
            ->where('is_active', 1)
            // normalize leader_role (hapus spasi)
            ->whereIn(DB::raw("LOWER(REPLACE(TRIM(leader_role),' ',''))"), $aliases)
            // overlap dates
            ->whereDate('effective_from', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $start);
            })
            ->pluck('user_id')
            ->map(fn($x) => (int)$x)
            ->values()
            ->all();
    }

    private function resolveScopeUserIds($leader, Carbon $period): array
{
    $leaderId   = (int)($leader?->id ?? 0);
    $leaderRole = $this->leaderRoleValue($leader);
    if ($leaderId <= 0) return [];

    $start = $period->copy()->startOfMonth()->toDateString();
    $end   = $period->copy()->endOfMonth()->toDateString();

    $aliases = match ($leaderRole) {
        'TLFE' => ['tlfe','tl','teamleader','leader'],
        'KSFE' => ['ksfe','kasi','kasilending'],
        default => [strtolower($leaderRole)],
    };

    return DB::table('org_assignments')
        ->where('leader_id', $leaderId)
        ->where('is_active', 1)
        ->whereIn(DB::raw("LOWER(REPLACE(TRIM(leader_role),' ',''))"), $aliases)
        ->whereDate('effective_from', '<=', $end)
        ->where(function ($q) use ($start) {
            $q->whereNull('effective_to')
              ->orWhereDate('effective_to', '>=', $start);
        })
        ->pluck('user_id')
        ->map(fn($x) => (int)$x)
        ->values()
        ->all();
}

    private function buildTlFeRecap($items, string $periodDate, $leaderUser = null): ?object
    {
        $leader = $leaderUser ?: auth()->user();
        if (!$leader) return null;

        $leaderRole = $this->leaderRoleValue($leader);

        // recap hanya relevan untuk TLFE/KSFE/KBL (kalau bukan, return null)
        if (!in_array($leaderRole, ['TLFE', 'KSFE', 'KBL'], true)) {
            return null;
        }

        $scopeUserIds = $this->scopeFeUserIdsForLeader($leader, Carbon::parse($periodDate));
        if (empty($scopeUserIds)) return null;

        $scoped = $items->filter(fn($it) => in_array((int)$it->user_id, $scopeUserIds, true));
        if ($scoped->count() <= 0) return null;

        $ranked = $scoped->sortByDesc(fn($it) => (float)($it->pi_total ?? 0))->values();

        $rankings = $ranked->map(function ($it, $i) {
            return (object)[
                'rank'     => $i + 1,
                'user_id'  => (int)($it->user_id ?? 0),
                'name'     => (string)($it->name ?? '-'),
                'ao_code'  => (string)($it->ao_code ?? '-'),
                'pi_total' => (float)($it->pi_total ?? 0),

                'pi_os'  => (float)($it->pi_nett_os_down ?? 0),
                'pi_mg'  => (float)($it->pi_npl_migration ?? 0),
                'pi_pen' => (float)($it->pi_penalty ?? 0),

                'ach_os'  => (float)($it->ach_nett_os_down ?? 0),
                'ach_mg'  => (float)($it->ach_npl_migration ?? 0),
                'ach_pen' => (float)($it->ach_penalty ?? 0),
            ];
        });

        $sumTargetOsTurun = (float)$scoped->sum('target_os_turun_kol2');
        $sumOsTurunMurni  = (float)$scoped->sum('os_kol2_turun_murni');
        $sumOsTurunTotal  = (float)$scoped->sum('os_kol2_turun_total');
        $sumOsTurunMigr   = (float)$scoped->sum('os_kol2_turun_migrasi');

        $sumOsAwal        = (float)$scoped->sum('os_kol2_awal');
        $sumPctInfo = ($sumOsAwal > 0)
            ? round(($sumOsTurunMurni / $sumOsAwal) * 100.0, 4)
            : 0.0;

        $sumPenaltyTarget = (float)$scoped->sum('target_penalty_paid');
        $sumPenalty       = (float)$scoped->sum('penalty_paid_total');

        $sumMigrasiOs     = (float)$scoped->sum('migrasi_npl_os');

        $migrasiPctTotal = ($sumOsAwal > 0)
            ? round(($sumMigrasiOs / $sumOsAwal) * 100.0, 4)
            : 0.0;

        $targetMigrasiTl = 0.3000;
        if ($sumOsAwal > 0) {
            $targetMigrasiTl = round(
                $scoped->sum(function ($it) {
                    $t = (float)($it->target_migrasi_npl_pct ?? 0.3000);
                    $w = (float)($it->os_kol2_awal ?? 0);
                    return $t * $w;
                }) / $sumOsAwal,
            4);
        }

        $achOsTurun = $this->pct($sumOsTurunMurni, $sumTargetOsTurun);
        $achPenalty = $this->pct($sumPenalty, $sumPenaltyTarget);
        $achMigrasi = $this->achReverse($migrasiPctTotal, $targetMigrasiTl);

        $scoreOsTurun = $this->scoreFromAchievement($achOsTurun);
        $scorePenalty = $this->scoreFromAchievement($achPenalty);
        $scoreMigrasi = $this->scoreMigrasiFromPct($migrasiPctTotal);

        $piOsTurun = round($scoreOsTurun * ($this->weights['os_turun'] ?? 0), 2);
        $piMigrasi = round($scoreMigrasi * ($this->weights['migrasi'] ?? 0), 2);
        $piPenalty = round($scorePenalty * ($this->weights['penalty'] ?? 0), 2);

        return (object)[
            'name'        => $leader?->name ?? '-',
            'leader_role' => $leaderRole,
            'scope_count' => $scoped->count(),

            'target_nett_os_down'   => $sumTargetOsTurun,
            'nett_os_down'          => $sumOsTurunMurni,
            'nett_os_down_total'    => $sumOsTurunTotal,
            'nett_os_down_migrasi'  => $sumOsTurunMigr,
            'nett_os_down_pct_info' => $sumPctInfo,

            'ach_nett_os_down'   => $achOsTurun,
            'score_nett_os_down' => $scoreOsTurun,
            'pi_nett_os_down'    => $piOsTurun,

            'target_npl_migration_pct' => $targetMigrasiTl,
            'npl_migration_pct'        => $migrasiPctTotal,
            'ach_npl_migration'        => $achMigrasi,
            'score_npl_migration'      => $scoreMigrasi,
            'pi_npl_migration'         => $piMigrasi,

            'target_penalty'   => $sumPenaltyTarget,
            'penalty_actual'   => $sumPenalty,
            'ach_penalty'      => $achPenalty,
            'score_penalty'    => $scorePenalty,
            'pi_penalty'       => $piPenalty,

            'pi_total' => round($piOsTurun + $piMigrasi + $piPenalty, 2),

            'rankings' => $rankings,
        ];
    }
}