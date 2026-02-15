<?php

namespace App\Http\Controllers\Kpi;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Kpi\FeKpiMonthlyService;

class MarketingKpiSheetController
{
    // =========================
    // Helpers (score & math)
    // =========================
    private function resolveRoMode(Carbon $period): string
    {
        return $period->greaterThanOrEqualTo(now()->startOfMonth()) ? 'realtime' : 'eom';
    }

    private function pct($actual, $target): float
    {
        $a = (float)($actual ?? 0);
        $t = (float)($target ?? 0);
        if ($t == 0.0) return 0.0;
        return round(($a / $t) * 100.0, 2);
    }

    private function scoreFromAch(float $ach): float
    {
        // contoh: <=60=1, 60-79=2, 80-89=3, 90-99=4, >=100=6
        if ($ach >= 100) return 6.0;
        if ($ach >= 90)  return 4.0;
        if ($ach >= 80)  return 3.0;
        if ($ach >= 60)  return 2.0;
        return 1.0;
    }

    private function scoreReverseFromActualVsTarget(float $actualPct, float $targetPct): float
    {
        // reverse: makin kecil actual dibanding target makin bagus
        if ($targetPct <= 0) return 1.0;

        $ratio = ($actualPct / $targetPct) * 100.0; // <=100 bagus
        if ($ratio <= 100) return 6.0;
        if ($ratio <= 120) return 4.0;
        if ($ratio <= 150) return 3.0;
        if ($ratio <= 200) return 2.0;
        return 1.0;
    }

    private function achReverse(float $actualPct, float $targetPct): float
    {
        // Pencapaian reverse: makin kecil actual makin bagus
        if ($targetPct <= 0) return 0;
        if ($actualPct <= 0) return 100.0;
        return round(($targetPct / $actualPct) * 100.0, 2);
    }

    // =========================
    // Scope org_assignments
    // =========================
    private function resolveTlScopeUserIds(string $leaderRole, int $leaderId, string $periodDate): array
    {
        $leaderRole = strtoupper(trim((string) $leaderRole));

        // ✅ role alias / normalisasi (biar TLRO tetap kebaca walau disimpan TL)
        $roleAliases = match ($leaderRole) {
            'TLRO' => ['tlro', 'tl', 'teamleader', 'leader'], // tambah kalau di data kamu ada varian lain
            'KSL'  => ['ksl', 'kasi', 'kasi lending'],
            'KBL'  => ['kbl', 'kabag', 'kabag lending'],
            default => [strtolower($leaderRole)],
        };

        return DB::table('org_assignments')
            ->where('leader_id', $leaderId)
            ->whereIn(DB::raw('LOWER(leader_role)'), $roleAliases)
            ->where('is_active', 1)
            ->whereDate('effective_from', '<=', $periodDate)
            ->where(function ($q) use ($periodDate) {
                $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $periodDate);
            })
            ->pluck('user_id')
            ->map(fn ($x) => (int)$x)
            ->values()
            ->all();
    }

    private function leaderRoleValue($user): string
    {
        $role = '';

        if ($user) {
            // prioritas helper roleValue() kalau ada
            if (method_exists($user, 'roleValue')) {
                $role = (string)($user->roleValue() ?? '');
            }

            // fallback: level
            if (trim($role) === '') {
                $role = (string)($user->level ?? '');
            }
        }

        $role = strtoupper(trim($role));
        return $role !== '' ? $role : 'LEADER';
    }

    private function scopeAoCodesForLeader($leader, string $periodDate): array
    {
        $leaderId   = (int)($leader?->id ?? 0);
        $leaderRole = $this->leaderRoleValue($leader);

        if ($leaderId <= 0) return [];

        $scopeUserIds = $this->resolveTlScopeUserIds($leaderRole, $leaderId, $periodDate);
        if (empty($scopeUserIds)) return [];

        // Ambil ao_code RO dalam scope leader
        return DB::table('users')
            ->whereIn('id', $scopeUserIds)
            ->whereRaw("UPPER(TRIM(level)) = 'RO'")
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '')
            ->pluck('ao_code')
            ->map(fn ($x) => (string)$x)
            ->values()
            ->all();

    }

    // =========================
    // Controller
    // =========================
    public function index(Request $request)
    {
        // =========================
        // Period (Y-m) safe parse
        // =========================
        $periodYm = (string) $request->query('period', now()->format('Y-m'));

        try {
            $period = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        } catch (\Throwable $e) {
            $periodYm = now()->format('Y-m');
            $period   = now()->startOfMonth();
        }

        $periodDate = $period->toDateString();

        // =========================
        // Role selector (AO|SO|RO|FE|BE)
        // =========================
        $role = strtoupper((string) $request->query('role', 'AO'));
        if (!in_array($role, ['AO', 'SO', 'RO', 'FE', 'BE'], true)) $role = 'AO';

        // ==========================================================
        // RO (auto mode: bulan ini realtime, bulan lalu ke bawah EOM)
        // ==========================================================
        if ($role === 'RO') {
            $mode = $this->resolveRoMode($period); // realtime | eom

            $weights = [
                'repayment' => 0.40,
                'topup'     => 0.20,
                'noa'       => 0.10,
                'dpk'       => 0.30,
            ];

            // target per ao_code (optional, kalau belum ada pakai default)
            $targetMap = DB::table('kpi_ro_targets')
                ->whereDate('period', $periodDate)
                ->get()
                ->keyBy('ao_code');

            $leader = auth()->user();
            $leaderRole = $this->leaderRoleValue($leader);

            $scopeAoCodes = $this->scopeAoCodesForLeader($leader, $periodDate);

            // kalau leader TLRO/KSL/KBL, default hanya scope (kalau ada)
            $isLeader = in_array($leaderRole, ['TLRO','KSL','KBL','PE','DIR','KOM','DIREKSI'], true);

            $rowsQ = DB::table('users as u')
                ->whereRaw("UPPER(TRIM(u.level)) = 'RO'")
                ->whereNotNull('u.ao_code')
                ->whereRaw("TRIM(u.ao_code) <> ''");

            if ($isLeader && !empty($scopeAoCodes)) {
                $rowsQ->whereIn('u.ao_code', array_values($scopeAoCodes));
            }

            $rows = $rowsQ
                ->leftJoin('kpi_ro_monthly as k', function ($j) use ($periodDate, $mode) {
                    $j->on('k.ao_code', '=', 'u.ao_code')
                    ->where('k.period_month', '=', $periodDate)  // ✅ aman
                    ->where('k.calc_mode', '=', $mode);
                })
                ->selectRaw("
                    u.id as user_id, u.name, u.ao_code, u.level,

                    COALESCE(k.total_score_weighted, 0) as score_total,

                    COALESCE(k.repayment_rate, 0) as repayment_rate,
                    COALESCE(k.repayment_pct, 0)  as repayment_pct,
                    COALESCE(k.repayment_score, 0) as repayment_score,

                    COALESCE(k.topup_realisasi, 0) as topup_realisasi,
                    COALESCE(k.topup_target, 0)    as topup_target,
                    COALESCE(k.topup_pct, 0)       as topup_pct,
                    COALESCE(k.topup_score, 0)     as topup_score,

                    COALESCE(k.noa_realisasi, 0) as noa_realisasi,
                    COALESCE(k.noa_target, 0)    as noa_target,
                    COALESCE(k.noa_pct, 0)       as noa_pct,
                    COALESCE(k.noa_score, 0)     as noa_score,

                    COALESCE(k.dpk_pct, 0)   as dpk_pct,
                    COALESCE(k.dpk_score, 0) as dpk_score,

                    COALESCE(k.dpk_migrasi_count, 0) as dpk_migrasi_count,
                    COALESCE(k.dpk_migrasi_os, 0)    as dpk_migrasi_os,
                    COALESCE(k.dpk_total_os_akhir, 0) as dpk_total_os_akhir,

                    COALESCE(k.baseline_ok, 0) as baseline_ok,
                    k.baseline_note as baseline_note
                ")
                ->orderBy('u.name')
                ->get();

            $items = $rows->map(function ($r) use ($weights, $targetMap) {

                // ===== RR actual percent (0..100) =====
                $rrPct = (float)($r->repayment_pct ?? 0);

                // fallback kalau ternyata pct kosong tapi rate ada (rate 0..1)
                if ($rrPct <= 0 && ($r->repayment_rate ?? null) !== null && (float)$r->repayment_rate > 0) {
                    $rrPct = ((float)$r->repayment_rate) * 100.0;
                }

                // ===== Targets (fallback default) =====
                $tg = $targetMap->get($r->ao_code);

                $targetTopup = (float)($tg->target_topup ?? 750_000_000);
                $targetNoa   = (int)  ($tg->target_noa ?? 2);
                $targetRr    = (float)($tg->target_rr_pct ?? 100.0);
                $targetDpk   = (float)($tg->target_dpk_pct ?? 1.00); // default 1% (target pemburukan <1%)

                // ===== Achievement =====
                $achRr = $targetRr > 0 ? round(($rrPct / $targetRr) * 100, 2) : 0;

                $topupReal = (float)($r->topup_realisasi ?? 0);
                $achTopup  = $targetTopup > 0 ? round(($topupReal / $targetTopup) * 100, 2) : 0;

                $noaReal = (int)($r->noa_realisasi ?? 0);
                $achNoa  = $targetNoa > 0 ? round(($noaReal / $targetNoa) * 100, 2) : 0;

                // ===== DPK (reverse) =====
                $dpkActual = (float)($r->dpk_pct ?? 0); // actual %
                $achDpk = 0.0;
                if ($targetDpk > 0) {
                    $achDpk = ($dpkActual <= 0) ? 100.0 : round(($targetDpk / $dpkActual) * 100.0, 2);
                    $achDpk = min($achDpk, 200.0); // optional cap
                }

                // ===== PI =====
                $piRepay = round(((float)($r->repayment_score ?? 0)) * $weights['repayment'], 2);
                $piTopup = round(((float)($r->topup_score ?? 0))     * $weights['topup'], 2);
                $piNoa   = round(((float)($r->noa_score ?? 0))       * $weights['noa'], 2);
                $piDpk   = round(((float)($r->dpk_score ?? 0))       * $weights['dpk'], 2);

                $piTotal = round($piRepay + $piTopup + $piNoa + $piDpk, 2);

                return (object) array_merge((array)$r, [
                    // display RR
                    'repayment_pct_display' => $rrPct,

                    // targets
                    'target_rr_pct'  => $targetRr,
                    'target_topup'   => $targetTopup,
                    'target_noa'     => $targetNoa,
                    'target_dpk_pct' => $targetDpk,

                    // achievement (dipakai di kolom "Pencapaian")
                    'ach_rr'    => $achRr,
                    'ach_topup' => $achTopup,
                    'ach_noa'   => $achNoa,
                    'ach_dpk'   => $achDpk,

                    // PI
                    'pi_repayment' => $piRepay,
                    'pi_topup'     => $piTopup,
                    'pi_noa'       => $piNoa,
                    'pi_dpk'       => $piDpk,
                    'pi_total'     => $piTotal,
                ]);
            });

            // ======================================================
            // TL/KBL/KSL Recap (rekap scope berdasarkan org_assignments)
            // ======================================================
            $leader     = auth()->user();
            $leaderRole = $this->leaderRoleValue($leader);

            // ambil ao_code RO scope leader via org_assignments
            $scopeAoCodes = $this->scopeAoCodesForLeader($leader, $periodDate);

            $tlRecap = null;

            if (!empty($scopeAoCodes)) {
                $scoped = $items->filter(fn($it) => in_array((string)$it->ao_code, $scopeAoCodes, true));

                $sumTopupActual = (float) $scoped->sum('topup_realisasi');
                $sumTopupTarget = (float) $scoped->sum('target_topup');

                $sumNoaActual   = (int) $scoped->sum('noa_realisasi');
                $sumNoaTarget   = (int) $scoped->sum('target_noa');

                $sumMigrasiOs   = (float) $scoped->sum('dpk_migrasi_os');
                $sumOsAkhir     = (float) $scoped->sum('dpk_total_os_akhir');

                // RR: avg scope
                $rrAvg = $scoped->count() > 0
                    ? round((float)$scoped->avg('repayment_pct_display'), 2)
                    : 0.0;

                $sumOs = (float) $scoped->sum('dpk_total_os_akhir');

                $rrWeighted = 0.0;

                if ($sumOs > 0) {
                    $rrWeighted = round(
                        $scoped->sum(function ($it) {
                            $rr = (float)($it->repayment_pct_display ?? 0);
                            $os = (float)($it->dpk_total_os_akhir ?? 0);
                            return $rr * $os;
                        }) / $sumOs
                    , 2);
                }

                $rrUsed = $rrWeighted > 0 ? $rrWeighted : $rrAvg; // fallback kalau OS=0
                $targetRr = 100.0;

                $achRr = $targetRr > 0 ? round(($rrUsed / $targetRr) * 100.0, 2) : 0.0;

                $achTopup = $sumTopupTarget > 0 ? round(($sumTopupActual / $sumTopupTarget) * 100.0, 2) : 0.0;
                $achNoa   = $sumNoaTarget   > 0 ? round(($sumNoaActual   / $sumNoaTarget)   * 100.0, 2) : 0.0;

                $dpkPctTotal = $sumOsAkhir > 0 ? round(($sumMigrasiOs / $sumOsAkhir) * 100.0, 2) : 0.0;

                // target DPK TL: weighted by os_akhir (lebih fair)
                $targetDpkTl = 1.0;
                if ($sumOsAkhir > 0) {
                    $targetDpkTl = round(
                        $scoped->sum(function ($it) {
                            $t = (float)($it->target_dpk_pct ?? 1.0);
                            $w = (float)($it->dpk_total_os_akhir ?? 0);
                            return $t * $w;
                        }) / $sumOsAkhir
                    , 2);
                }

                $scoreRepay = $this->scoreFromAch($achRr);
                $scoreTopup = $this->scoreFromAch($achTopup);
                $scoreNoa   = $this->scoreFromAch($achNoa);
                $scoreDpk   = $this->scoreReverseFromActualVsTarget($dpkPctTotal, $targetDpkTl);

                $piRepay = round($scoreRepay * $weights['repayment'], 2);
                $piTopup = round($scoreTopup * $weights['topup'], 2);
                $piNoa   = round($scoreNoa   * $weights['noa'], 2);
                $piDpk   = round($scoreDpk   * $weights['dpk'], 2);

                $tlRecap = (object)[
                    'name'        => $leader?->name ?? '-',
                    'leader_role' => $leaderRole,
                    'scope_count' => count($scopeAoCodes), // jumlah RO dalam scope

                    // targets (total/avg)
                    'target_topup_total' => $sumTopupTarget,
                    'target_noa_total'   => $sumNoaTarget,
                    'target_rr_pct'      => $targetRr,
                    'target_dpk_pct'     => $targetDpkTl,

                    // actual (total/avg)
                    'topup_actual_total' => $sumTopupActual,
                    'noa_actual_total'   => $sumNoaActual,
                    'rr_actual_avg'      => $rrUsed,
                    'dpk_actual_pct'     => $dpkPctTotal,

                    // achievement
                    'ach_topup' => $achTopup,
                    'ach_noa'   => $achNoa,
                    'ach_rr'    => $achRr,
                    'ach_dpk'   => $this->achReverse($dpkPctTotal, $targetDpkTl),

                    // score
                    'score_repayment' => $scoreRepay,
                    'score_topup'     => $scoreTopup,
                    'score_noa'       => $scoreNoa,
                    'score_dpk'       => $scoreDpk,

                    // PI
                    'pi_repayment' => $piRepay,
                    'pi_topup'     => $piTopup,
                    'pi_noa'       => $piNoa,
                    'pi_dpk'       => $piDpk,
                    'pi_total'     => round($piRepay + $piTopup + $piNoa + $piDpk, 2),
                ];
            }

            return view('kpi.marketing.sheet', [
                'role'     => $role,
                'periodYm' => $periodYm,
                'period'   => $period,
                'mode'     => $mode,
                'weights'  => $weights,
                'items'    => $items,
                'tlRecap'  => $tlRecap,
            ]);
        }

        // ========= SO =========
        if ($role === 'SO') {
            $weights = [
                'os'       => 0.55,
                'noa'      => 0.15,
                'rr'       => 0.20,
                'activity' => 0.10,
            ];

            $rows = DB::table('kpi_so_monthlies as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->leftJoin('kpi_so_targets as t', function ($j) use ($periodDate) {
                    $j->on('t.user_id', '=', 'm.user_id')
                        ->where('t.period', '=', $periodDate);
                })
                ->where('m.period', $periodDate)
                ->where('u.level', 'SO')
                ->select([
                    'u.id as user_id','u.name','u.ao_code','u.level',
                    't.id as target_id',
                    't.target_os_disbursement',
                    't.target_noa_disbursement',
                    't.target_rr',
                    't.target_activity',

                    'm.os_disbursement',
                    'm.os_disbursement_raw',
                    'm.os_adjustment',

                    'm.noa_disbursement',
                    'm.rr_pct',
                    'm.activity_actual',
                    'm.score_os','m.score_noa','m.score_rr','m.score_activity',
                    'm.score_total',
                ])
                ->orderBy('u.name')
                ->get();

            $items = $rows->map(function ($r) use ($weights) {
                $achOs  = $this->pct($r->os_disbursement ?? 0, $r->target_os_disbursement ?? 0);
                $achNoa = $this->pct($r->noa_disbursement ?? 0, $r->target_noa_disbursement ?? 0);
                $achAct = $this->pct($r->activity_actual ?? 0, $r->target_activity ?? 0);

                $targetRr = (float)($r->target_rr ?? 100);

                $piOs  = round(((float)($r->score_os ?? 0))       * $weights['os'], 2);
                $piNoa = round(((float)($r->score_noa ?? 0))      * $weights['noa'], 2);
                $piRr  = round(((float)($r->score_rr ?? 0))       * $weights['rr'], 2);
                $piAct = round(((float)($r->score_activity ?? 0)) * $weights['activity'], 2);

                $totalPi = round($piOs + $piNoa + $piRr + $piAct, 2);

                return (object) array_merge((array)$r, [
                    'ach_os'       => $achOs,
                    'ach_noa'      => $achNoa,
                    'ach_rr'       => $targetRr > 0 ? round(((float)($r->rr_pct ?? 0) / $targetRr) * 100, 2) : 0,
                    'ach_activity' => $achAct,
                    'target_rr'    => $targetRr,

                    'pi_os'       => $piOs,
                    'pi_noa'      => $piNoa,
                    'pi_rr'       => $piRr,
                    'pi_activity' => $piAct,
                    'pi_total'    => $totalPi,
                ]);
            });

            return view('kpi.marketing.sheet', [
                'role'     => $role,
                'periodYm' => $periodYm,
                'period'   => $period,
                'weights'  => $weights,
                'items'    => $items,
            ]);
        }

        // ========= FE =========
        if ($role === 'FE') {
            $svc = app(\App\Services\Kpi\FeKpiMonthlyService::class);

            $res = $svc->buildForPeriod($periodYm, auth()->user());

            return view('kpi.marketing.sheet', [
                'role'      => $role,
                'periodYm'  => $periodYm,
                'period'    => $res['period'],
                'mode'      => $res['mode'] ?? null,
                'weights'   => $res['weights'],
                'items'     => $res['items'],
                'tlRecap' => $res['tlFeRecap'] ?? null,
            ]);
        }

        // ========= AO =========
        $weights = [
            'os'       => 0.35,
            'noa'      => 0.15,
            'rr'       => 0.25,
            'kolek'    => 0.15,
            'activity' => 0.10,
        ];

        $rows = DB::table('kpi_ao_monthlies as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('kpi_ao_targets as t', function ($j) use ($periodDate) {
                $j->on('t.user_id', '=', 'm.user_id')
                    ->where('t.period', '=', $periodDate);
            })
            ->where('m.period', $periodDate)
            ->where('u.level', 'AO')
            ->select([
                'u.id as user_id','u.name','u.ao_code','u.level',
                't.id as target_id',
                't.target_os_growth',
                't.target_noa_growth',
                't.target_activity',
                'm.os_growth',
                'm.noa_growth',
                'm.rr_pct',
                'm.npl_migration_pct',
                'm.activity_actual',
                'm.score_os','m.score_noa','m.score_rr','m.score_kolek','m.score_activity',
                'm.score_total',
            ])
            ->orderBy('u.name')
            ->get();

        $items = $rows->map(function ($r) use ($weights) {
            $achOs  = $this->pct($r->os_growth ?? 0, $r->target_os_growth ?? 0);
            $achNoa = $this->pct($r->noa_growth ?? 0, $r->target_noa_growth ?? 0);
            $achAct = $this->pct($r->activity_actual ?? 0, $r->target_activity ?? 0);

            $piOs   = round(((float)($r->score_os ?? 0))       * $weights['os'], 2);
            $piNoa  = round(((float)($r->score_noa ?? 0))      * $weights['noa'], 2);
            $piRr   = round(((float)($r->score_rr ?? 0))       * $weights['rr'], 2);
            $piKol  = round(((float)($r->score_kolek ?? 0))    * $weights['kolek'], 2);
            $piAct  = round(((float)($r->score_activity ?? 0)) * $weights['activity'], 2);

            $totalPi = round($piOs + $piNoa + $piRr + $piKol + $piAct, 2);

            return (object) array_merge((array)$r, [
                'ach_os'       => $achOs,
                'ach_noa'      => $achNoa,
                'ach_rr'       => (float)($r->rr_pct ?? 0),
                'ach_kolek'    => (float)($r->npl_migration_pct ?? 0),
                'ach_activity' => $achAct,

                'pi_os'       => $piOs,
                'pi_noa'      => $piNoa,
                'pi_rr'       => $piRr,
                'pi_kolek'    => $piKol,
                'pi_activity' => $piAct,
                'pi_total'    => $totalPi,
            ]);
        });

        return view('kpi.marketing.sheet', [
            'role'     => $role,
            'periodYm' => $periodYm,
            'period'   => $period,
            'weights'  => $weights,
            'items'    => $items,
        ]);

    }
}
