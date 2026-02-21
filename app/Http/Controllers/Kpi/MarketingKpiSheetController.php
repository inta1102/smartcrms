<?php

namespace App\Http\Controllers\Kpi;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Kpi\KpiScoreHelper;

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
    private function resolveTlScopeUserIds(string $leaderRole, int $leaderId, ?string $periodDate): array
    {
        $leaderRole = strtoupper(trim((string) $leaderRole));

        $roleAliases = match ($leaderRole) {
            'TLRO' => ['tlro', 'tl', 'teamleader', 'leader'],
            'KSLU'  => ['kslu', 'kasi', 'kasi lending'],
            'KSLR'  => ['kslr', 'kasi', 'kasi lending'],
            'KSFE'  => ['ksbe', 'kasi', 'kasi lending'],
            'KSBE'  => ['ksfe', 'kasi', 'kasi lending'],
            'KBL'  => ['kbl', 'kabag', 'kabag lending'],
            default => [strtolower($leaderRole)],
        };

        // 1) query dasar: leader + role + aktif
        $baseQ = DB::table('org_assignments')
            ->where('leader_id', $leaderId)
            ->where('is_active', 1)
            // ✅ TRIM + LOWER biar "TLRO " tetap match
            ->whereIn(DB::raw('LOWER(TRIM(leader_role))'), $roleAliases);

        // 2) kalau periodDate ada, pakai effective range
        $q = clone $baseQ;
        if (!empty($periodDate)) {
            $q->whereDate('effective_from', '<=', $periodDate)
              ->where(function ($qq) use ($periodDate) {
                  $qq->whereNull('effective_to')
                     ->orWhereDate('effective_to', '>=', $periodDate);
              });
        }

        $ids = $q->pluck('user_id')
            ->filter()
            ->map(fn ($x) => (int) $x)
            ->unique()
            ->values()
            ->all();

        // 3) ✅ fallback: kalau kosong di periode tsb, ambil assignment terbaru yg aktif
        if (!empty($periodDate) && empty($ids)) {
            $ids = (clone $baseQ)
                ->orderByDesc('effective_from')
                ->pluck('user_id')
                ->filter()
                ->map(fn ($x) => (int) $x)
                ->unique()
                ->values()
                ->all();
        }

        return $ids;
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
            $isLeader = in_array($leaderRole, ['TLRO','KSLU','KSLR','KSBE','KSFE','KBL','PE','DIR','KOM','DIREKSI'], true);

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
                      ->where('k.period_month', '=', $periodDate)
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
                    k.baseline_note as baseline_note,

                    -- ✅ TAMBAHAN (agar TL recap & detail topup/RR bisa informatif)
                    COALESCE(k.topup_cif_count, 0) as topup_cif_count,
                    COALESCE(k.topup_cif_new_count, 0) as topup_cif_new_count,
                    COALESCE(k.topup_max_cif_amount, 0) as topup_max_cif_amount,
                    COALESCE(k.topup_concentration_pct, 0) as topup_concentration_pct,
                    k.topup_top3_json as topup_top3_json,

                    COALESCE(k.repayment_total_os, 0) as repayment_total_os,
                    COALESCE(k.repayment_os_lancar, 0) as repayment_os_lancar
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

                $targetTopup = (float)($tg->target_topup ?? 0);
                $targetNoa   = (int)  ($tg->target_noa ?? 0);
                $targetRr    = (float)($tg->target_rr_pct ?? 0);
                $targetDpk   = (float)($tg->target_dpk_pct ?? 0); // default 1% (target pemburukan <1%)

                // ===== Achievement (pakai helper biar konsisten & anti target=0 free score) =====
                $achRr    = KpiScoreHelper::achievementPct($rrPct, $targetRr);           // rrPct vs targetRr
                $scoreRr  = KpiScoreHelper::scoreBand1to6($achRr);

                $topupReal  = (float)($r->topup_realisasi ?? 0);
                $achTopup   = KpiScoreHelper::achievementPct($topupReal, $targetTopup);
                $scoreTopup = KpiScoreHelper::scoreBand1to6($achTopup);

                $noaReal  = (int)($r->noa_realisasi ?? 0);
                $achNoa   = KpiScoreHelper::achievementPct((float)$noaReal, (float)$targetNoa);
                $scoreNoa = KpiScoreHelper::scoreBand1to6($achNoa);

                // ===== DPK (reverse) =====
                // actual dpkActual (%), targetDpk (% batas)
                $dpkActual = (float)($r->dpk_pct ?? 0);
                $achDpk = 0.0;

                if ($targetDpk > 0) {
                    // kalau actual 0 => perfect (100)
                    $achDpk = ($dpkActual <= 0) ? 100.0 : round(($targetDpk / $dpkActual) * 100.0, 2);
                    $achDpk = min($achDpk, 200.0); // optional cap
                }
                $scoreDpk = KpiScoreHelper::scoreBand1to6($achDpk);

                // ===== PI (PAKAI score hasil helper, bukan DB) =====
                $piRepay = round($scoreRr    * $weights['repayment'], 2);
                $piTopup = round($scoreTopup * $weights['topup'], 2);
                $piNoa   = round($scoreNoa   * $weights['noa'], 2);
                $piDpk   = round($scoreDpk   * $weights['dpk'], 2);

                $piTotal = round($piRepay + $piTopup + $piNoa + $piDpk, 2);

                return (object) array_merge((array)$r, [
                    'repayment_pct_display' => $rrPct,
                    
                    // targets
                    'target_rr_pct'  => $targetRr,
                    'target_topup'   => $targetTopup,
                    'target_noa'     => $targetNoa,
                    'target_dpk_pct' => $targetDpk,

                    // achievement
                    'ach_rr'    => $achRr,
                    'ach_topup' => $achTopup,
                    'ach_noa'   => $achNoa,
                    'ach_dpk'   => $achDpk,

                    // ✅ OVERRIDE score final (jangan pakai score dari DB)
                    'repayment_score' => $scoreRr,
                    'topup_score'     => $scoreTopup,
                    'noa_score'       => $scoreNoa,
                    'dpk_score'       => $scoreDpk,

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

                $sumTopupCifCount = (int) $scoped->sum('topup_cif_count');
                $sumTopupCifNew   = (int) $scoped->sum('topup_cif_new_count');
                $maxTopupMaxCif   = (float) $scoped->max('topup_max_cif_amount');

                // konsentrasi TL
                $globalTop1 = (float) $scoped->max('topup_max_cif_amount');
                $tlConcentration = $sumTopupActual > 0 ? round(($globalTop1 / $sumTopupActual) * 100.0, 2) : 0.0;

                // top3 TL
                $all = [];
                foreach ($scoped as $it) {
                    $arr = [];
                    if (!empty($it->topup_top3_json)) {
                        $arr = json_decode($it->topup_top3_json, true) ?: [];
                    }
                    foreach ($arr as $x) $all[] = $x;
                }
                usort($all, fn($a,$b) => (float)($b['delta'] ?? 0) <=> (float)($a['delta'] ?? 0));
                $tlTop3 = array_slice($all, 0, 3);

                // RR avg / weighted
                $rrAvg = $scoped->count() > 0 ? round((float)$scoped->avg('repayment_pct_display'), 2) : 0.0;
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

                $rrUsed = $rrWeighted > 0 ? $rrWeighted : $rrAvg;
                $targetRr = 100.0;

                $achRr = $targetRr > 0 ? round(($rrUsed / $targetRr) * 100.0, 2) : 0.0;

                $achTopup = $sumTopupTarget > 0 ? round(($sumTopupActual / $sumTopupTarget) * 100.0, 2) : 0.0;
                $achNoa   = $sumNoaTarget   > 0 ? round(($sumNoaActual   / $sumNoaTarget)   * 100.0, 2) : 0.0;

                $dpkPctTotal = $sumOsAkhir > 0 ? round(($sumMigrasiOs / $sumOsAkhir) * 100.0, 2) : 0.0;

                // target DPK TL weighted by os_akhir
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
                    'scope_count' => count($scopeAoCodes),

                    // targets
                    'target_topup_total' => $sumTopupTarget,
                    'target_noa_total'   => $sumNoaTarget,
                    'target_rr_pct'      => $targetRr,
                    'target_dpk_pct'     => $targetDpkTl,

                    // actual
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

                    // topup meta
                    'topup_cif_count' => $sumTopupCifCount,
                    'topup_cif_new_count' => $sumTopupCifNew,
                    'topup_max_amount' => $maxTopupMaxCif,
                    'topup_concentration_pct' => $tlConcentration,
                    'topup_top3' => array_map(function($x){
                        $cif = $x['cif'] ?? '-';
                        $delta = (int)($x['delta'] ?? 0);
                        return "{$cif} – Rp " . number_format($delta,0,',','.');
                    }, $tlTop3),

                    // RR meta
                    'rr_os_exposure' => (float) $scoped->sum('repayment_total_os'),
                    'rr_paid_amount' => 0,
                    'rr_account_count' => 0,
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
                'tlRecap'   => $res['tlFeRecap'] ?? null,
            ]);
        }

        // ========= BE =========
        if ($role === 'BE') {
            $svc = app(\App\Services\Kpi\BeKpiMonthlyService::class);
            $res = $svc->buildForPeriod($periodYm, auth()->user());

            return view('kpi.marketing.sheet', [
                'role'     => $role,
                'periodYm' => $periodYm,
                'period'   => $res['period'],
                'mode'     => $res['mode'] ?? null,
                'weights'  => $res['weights'],
                'items'    => $res['items'],
                'tlRecap'  => $res['tlBeRecap'] ?? null,
            ]);
        }

        // ========= AO =========

        // weights AO UMKM (baru) - single mode
        $weightsUmkm = [
            'noa'       => 0.30,
            'os'        => 0.20,
            'rr'        => 0.25,
            'community' => 0.20,
            'daily'     => 0.05,
        ];

        // ====== TLUM SCOPE (untuk tabel TLUM + ranking) ======
        $me = $request->user();
        $tlum = null;
        $tlumRowsRank = collect();

        try {
            $roleAliases = ['tl', 'tlum', 'tl um', 'tl-um', 'tl_um', 'tl umkm', 'tl-umkm', 'tl_umkm'];

            $subUserIds = DB::table('org_assignments')
                ->where('leader_id', (int)($me?->id ?? 0))
                ->where('is_active', 1)
                ->whereIn(DB::raw('LOWER(TRIM(leader_role))'), $roleAliases)
                ->whereDate('effective_from', '<=', $periodDate)
                ->where(function ($q) use ($periodDate) {
                    $q->whereNull('effective_to')
                      ->orWhereDate('effective_to', '>=', $periodDate);
                })
                ->pluck('user_id')
                ->unique()
                ->values()
                ->all();

            $aoUserIds = empty($subUserIds) ? [] : DB::table('users')
                ->whereIn('id', $subUserIds)
                ->where('level', 'AO')
                ->pluck('id')
                ->unique()
                ->values()
                ->all();

            $baseRankQ = DB::table('kpi_ao_monthlies as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->leftJoin('kpi_ao_targets as t', function ($j) use ($periodDate) {
                    $j->on('t.user_id', '=', 'm.user_id')
                      ->where('t.period', '=', $periodDate);
                })
                ->where('m.period', $periodDate)
                ->where('m.scheme', 'AO_UMKM');

            if (!empty($aoUserIds)) {
                $baseRankQ->whereIn('u.id', $aoUserIds);
            }

            $rankRows = $baseRankQ
                ->select([
                    'u.id as user_id','u.name','u.ao_code',

                    't.target_os_disbursement',
                    't.target_noa_disbursement',
                    't.target_rr',
                    't.target_community',
                    't.target_daily_report',

                    'm.os_disbursement',
                    'm.noa_disbursement',
                    'm.os_disbursement_pct',
                    'm.noa_disbursement_pct',
                    'm.rr_pct',
                    'm.rr_os_total',
                    'm.rr_os_current',
                    'm.community_actual',
                    'm.community_pct',
                    'm.daily_report_actual',
                    'm.daily_report_pct',

                    'm.score_os',
                    'm.score_noa',
                    'm.score_rr',
                    'm.score_community',
                    'm.score_daily_report',
                    'm.score_total',
                ])
                ->orderByDesc('m.score_total')
                ->orderBy('u.name')
                ->get();

            $tlumRowsRank = $rankRows->map(function ($r) use ($weightsUmkm) {
                $piNoa = round(((float)($r->score_noa ?? 0))          * $weightsUmkm['noa'], 2);
                $piOs  = round(((float)($r->score_os ?? 0))           * $weightsUmkm['os'], 2);
                $piRr  = round(((float)($r->score_rr ?? 0))           * $weightsUmkm['rr'], 2);
                $piCom = round(((float)($r->score_community ?? 0))    * $weightsUmkm['community'], 2);
                $piDay = round(((float)($r->score_daily_report ?? 0)) * $weightsUmkm['daily'], 2);
                $piTot = round($piNoa + $piOs + $piRr + $piCom + $piDay, 2);

                return (object) array_merge((array)$r, [
                    'pi_noa' => $piNoa,
                    'pi_os'  => $piOs,
                    'pi_rr'  => $piRr,
                    'pi_community' => $piCom,
                    'pi_daily' => $piDay,
                    'pi_total' => $piTot,
                ]);
            })->sortByDesc('pi_total')->values();

            // 5) TLUM AGREGAT
            if ($tlumRowsRank->count() > 0) {
                $sumTargetNoa = (int) $tlumRowsRank->sum(fn($x) => (int)($x->target_noa_disbursement ?? 0));
                $sumTargetOs  = (int) $tlumRowsRank->sum(fn($x) => (int)($x->target_os_disbursement ?? 0));
                $avgTargetRr  = (float) ($tlumRowsRank->avg(fn($x) => (float)($x->target_rr ?? 100)) ?? 100.0);
                $sumTargetCom = (int) $tlumRowsRank->sum(fn($x) => (int)($x->target_community ?? 0));
                $sumTargetDay = (int) $tlumRowsRank->sum(fn($x) => (int)($x->target_daily_report ?? 0));

                $sumActualNoa = (int) $tlumRowsRank->sum(fn($x) => (int)($x->noa_disbursement ?? 0));
                $sumActualOs  = (int) $tlumRowsRank->sum(fn($x) => (int)($x->os_disbursement ?? 0));
                $sumActualCom = (int) $tlumRowsRank->sum(fn($x) => (int)($x->community_actual ?? 0));
                $sumActualDay = (int) $tlumRowsRank->sum(fn($x) => (int)($x->daily_report_actual ?? 0));

                $sumRrTotal   = (int) $tlumRowsRank->sum(fn($x) => (int)($x->rr_os_total ?? 0));
                $sumRrCurrent = (int) $tlumRowsRank->sum(fn($x) => (int)($x->rr_os_current ?? 0));
                $rrWeighted   = $sumRrTotal > 0 ? round(100.0 * $sumRrCurrent / $sumRrTotal, 2) : 0.0;

                $noaPct = \App\Services\Kpi\KpiScoreHelper::safePct((float)$sumActualNoa, (float)$sumTargetNoa);
                $osPct  = \App\Services\Kpi\KpiScoreHelper::safePct((float)$sumActualOs,  (float)$sumTargetOs);
                $comPct = \App\Services\Kpi\KpiScoreHelper::safePct((float)$sumActualCom, (float)$sumTargetCom);
                $dayPct = \App\Services\Kpi\KpiScoreHelper::safePct((float)$sumActualDay, (float)$sumTargetDay);

                $scoreNoaT = \App\Services\Kpi\KpiScoreHelper::scoreFromTlumNoaGrowth6($sumActualNoa);
                $scoreOsT  = \App\Services\Kpi\KpiScoreHelper::scoreFromAoOsRealisasiPct6($osPct);
                $scoreRrT  = \App\Services\Kpi\KpiScoreHelper::scoreFromRepaymentRateAo6($rrWeighted);
                $scoreComT = \App\Services\Kpi\KpiScoreHelper::scoreFromTlumCommunity6($sumActualCom);

                $wT = ['noa'=>0.30,'os'=>0.20,'rr'=>0.25,'com'=>0.20];

                $piNoaT = round($scoreNoaT * $wT['noa'], 2);
                $piOsT  = round($scoreOsT  * $wT['os'],  2);
                $piRrT  = round($scoreRrT  * $wT['rr'],  2);
                $piComT = round($scoreComT * $wT['com'], 2);
                $piTotT = round($piNoaT + $piOsT + $piRrT + $piComT, 2);

                $tlum = (object) [
                    'noa_target' => $sumTargetNoa,
                    'os_target'  => $sumTargetOs,
                    'rr_target'  => round($avgTargetRr, 2),
                    'com_target' => $sumTargetCom,
                    'day_target' => $sumTargetDay,

                    'noa_actual' => $sumActualNoa,
                    'os_actual'  => $sumActualOs,
                    'rr_actual'  => $rrWeighted,
                    'com_actual' => $sumActualCom,
                    'day_actual' => $sumActualDay,

                    'noa_pct' => round($noaPct, 2),
                    'os_pct'  => round($osPct, 2),
                    'rr_pct'  => $rrWeighted,
                    'com_pct' => round($comPct, 2),
                    'day_pct' => round($dayPct, 2),

                    'score_noa' => $scoreNoaT,
                    'score_os'  => $scoreOsT,
                    'score_rr'  => $scoreRrT,
                    'score_com' => $scoreComT,

                    'pi_noa' => $piNoaT,
                    'pi_os'  => $piOsT,
                    'pi_rr'  => $piRrT,
                    'pi_com' => $piComT,
                    'pi_total' => $piTotT,
                ];

                if (!empty($me?->id)) {
                    DB::table('kpi_tlum_monthlies')->updateOrInsert(
                        ['period' => $periodDate, 'tlum_user_id' => (int)$me->id],
                        [
                            'noa_target' => (int)($tlum->noa_target ?? 0),
                            'os_target'  => (int)($tlum->os_target ?? 0),
                            'rr_target'  => (float)($tlum->rr_target ?? 0),
                            'com_target' => (int)($tlum->com_target ?? 0),
                            'day_target' => (int)($tlum->day_target ?? 0),

                            'noa_actual' => (int)($tlum->noa_actual ?? 0),
                            'os_actual'  => (int)($tlum->os_actual ?? 0),
                            'rr_actual'  => (float)($tlum->rr_actual ?? 0),
                            'com_actual' => (int)($tlum->com_actual ?? 0),
                            'day_actual' => (int)($tlum->day_actual ?? 0),

                            'noa_pct' => (float)($tlum->noa_pct ?? 0),
                            'os_pct'  => (float)($tlum->os_pct ?? 0),
                            'com_pct' => (float)($tlum->com_pct ?? 0),
                            'day_pct' => (float)($tlum->day_pct ?? 0),

                            'score_noa' => (float)($tlum->score_noa ?? 0),
                            'score_os'  => (float)($tlum->score_os ?? 0),
                            'score_rr'  => (float)($tlum->score_rr ?? 0),
                            'score_com' => (float)($tlum->score_com ?? 0),

                            'pi_total' => (float)($tlum->pi_total ?? 0),
                            'calculated_at' => now(),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            }

        } catch (\Throwable $e) {
            \Log::warning('TLUM aggregate failed: '.$e->getMessage(), [
                'period'  => $periodDate,
                'user_id' => (int)($me?->id ?? 0),
            ]);
            $tlum = null;
            $tlumRowsRank = collect();
        }

        // ===== AO ITEMS (detail per AO) =====
        $rows = DB::table('kpi_ao_monthlies as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('kpi_ao_targets as t', function ($j) use ($periodDate) {
                $j->on('t.user_id', '=', 'm.user_id')
                  ->where('t.period', '=', $periodDate);
            })
            ->where('m.period', $periodDate)
            ->where('u.level', 'AO')
            ->where('m.scheme', 'AO_UMKM')
            ->select([
                'u.id as user_id','u.name','u.ao_code','u.level',
                'm.scheme',

                't.target_os_disbursement',
                't.target_noa_disbursement',
                't.target_rr',
                't.target_community',
                't.target_daily_report',

                'm.os_disbursement',
                'm.noa_disbursement',
                'm.os_disbursement_pct',
                'm.noa_disbursement_pct',
                'm.rr_pct',
                'm.community_actual',
                'm.community_pct',
                'm.daily_report_actual',
                'm.daily_report_pct',

                'm.score_os','m.score_noa','m.score_rr','m.score_community','m.score_daily_report',
                'm.score_total',
            ])
            ->orderBy('u.name')
            ->get();

        $items = $rows->map(function ($r) use ($weightsUmkm) {
            $piNoa = round(((float)($r->score_noa ?? 0))          * $weightsUmkm['noa'], 2);
            $piOs  = round(((float)($r->score_os ?? 0))           * $weightsUmkm['os'], 2);
            $piRr  = round(((float)($r->score_rr ?? 0))           * $weightsUmkm['rr'], 2);
            $piCom = round(((float)($r->score_community ?? 0))    * $weightsUmkm['community'], 2);
            $piDay = round(((float)($r->score_daily_report ?? 0)) * $weightsUmkm['daily'], 2);
            $piTot = round($piNoa + $piOs + $piRr + $piCom + $piDay, 2);

            return (object) array_merge((array)$r, [
                'mode' => 'AO_UMKM',

                'ach_noa'       => (float)($r->noa_disbursement_pct ?? 0),
                'ach_os'        => (float)($r->os_disbursement_pct ?? 0),
                'ach_rr'        => (float)($r->rr_pct ?? 0),
                'ach_community' => (float)($r->community_pct ?? 0),
                'ach_daily'     => (float)($r->daily_report_pct ?? 0),

                'pi_noa'       => $piNoa,
                'pi_os'        => $piOs,
                'pi_rr'        => $piRr,
                'pi_community' => $piCom,
                'pi_daily'     => $piDay,
                'pi_total'     => $piTot,
            ]);
        });

        $weights = $weightsUmkm;

        // ====== INSIGHT PANEL (TLUM) ======
        $insight = (object) [
            'best' => null,
            'worst' => null,
            'rr_gap' => null,
            'os_gap' => null,
            'noa_gap' => null,
            'community_gap' => null,
        ];

        if ($tlumRowsRank instanceof \Illuminate\Support\Collection && $tlumRowsRank->count() > 0) {
            $best  = $tlumRowsRank->sortByDesc(fn($x) => (float)($x->pi_total ?? 0))->first();
            $worst = $tlumRowsRank->sortBy(fn($x) => (float)($x->pi_total ?? 0))->first();

            $insight->best = $best ? (object)[
                'name' => $best->name ?? '-',
                'ao_code' => $best->ao_code ?? '-',
                'pi' => (float)($best->pi_total ?? 0),
                'rr' => (float)($best->rr_pct ?? 0),
                'os_pct' => (float)($best->os_disbursement_pct ?? 0),
                'noa' => (int)($best->noa_disbursement ?? 0),
            ] : null;

            $insight->worst = $worst ? (object)[
                'name' => $worst->name ?? '-',
                'ao_code' => $worst->ao_code ?? '-',
                'pi' => (float)($worst->pi_total ?? 0),
                'rr' => (float)($worst->rr_pct ?? 0),
                'os_pct' => (float)($worst->os_disbursement_pct ?? 0),
                'noa' => (int)($worst->noa_disbursement ?? 0),
            ] : null;
        }

        if (!empty($tlum)) {
            $insight->rr_gap = round(((float)($tlum->rr_target ?? 0)) - ((float)($tlum->rr_actual ?? 0)), 2);
            $insight->os_gap = max(0, (int)($tlum->os_target ?? 0) - (int)($tlum->os_actual ?? 0));
            $insight->noa_gap = max(0, (int)($tlum->noa_target ?? 0) - (int)($tlum->noa_actual ?? 0));
            $insight->community_gap = max(0, (int)($tlum->com_target ?? 0) - (int)($tlum->com_actual ?? 0));
        }

        // ===== Trend TLUM (MoM) =====
        $trend = null;
        if (!empty($me?->id)) {
            $prevPeriod = Carbon::parse($periodDate)->subMonth()->startOfMonth()->toDateString();

            $cur = DB::table('kpi_tlum_monthlies')
                ->where('period', $periodDate)
                ->where('tlum_user_id', (int)$me->id)
                ->first();

            $prev = DB::table('kpi_tlum_monthlies')
                ->where('period', $prevPeriod)
                ->where('tlum_user_id', (int)$me->id)
                ->first();

            if ($cur && $prev) {
                $delta = round(((float)$cur->pi_total) - ((float)$prev->pi_total), 2);
                $trend = (object) [
                    'prev_period' => $prevPeriod,
                    'prev_pi' => (float)$prev->pi_total,
                    'cur_pi'  => (float)$cur->pi_total,
                    'delta'   => $delta,
                ];
            }
        }

        return view('kpi.marketing.sheet', [
            'role'     => $role,
            'periodYm' => $periodYm,
            'period'   => $period,
            'weights'  => $weights,
            'items'    => $items,

            // ✅ TLUM
            'tlum'     => $tlum,
            'tlumRows' => $tlumRowsRank,
            'insight'  => $insight,
            'trend'    => $trend,
        ]);
    }

    // (dua helper ini belum dipakai di file ini, tapi aku biarkan kalau memang dipakai di bagian lain nantinya)
    private function periodDateFromYm(?string $periodYm): string
    {
        $p = $periodYm ?: now()->format('Y-m');
        return \Carbon\Carbon::parse($p.'-01')->startOfMonth()->toDateString();
    }

    private function scopeAoUserIdsForMe(\App\Models\User $me, string $periodDate): array
    {
        $roleAliases = ['tl', 'tlum', 'tl um', 'tl-um', 'tl_um', 'tl umkm', 'tl-umkm', 'tl_umkm'];

        $subUserIds = \DB::table('org_assignments')
            ->where('leader_id', (int)($me->id ?? 0))
            ->where('is_active', 1)
            ->whereIn(\DB::raw('LOWER(TRIM(leader_role))'), $roleAliases)
            ->whereDate('effective_from', '<=', $periodDate)
            ->where(function ($q) use ($periodDate) {
                $q->whereNull('effective_to')
                  ->orWhereDate('effective_to', '>=', $periodDate);
            })
            ->pluck('user_id')->unique()->values()->all();

        return empty($subUserIds) ? [] : \DB::table('users')
            ->whereIn('id', $subUserIds)
            ->where('level', 'AO')
            ->pluck('id')->unique()->values()->all();
    }
}
