<?php

namespace App\Http\Controllers\Kpi;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\Kpi\KpiScoreHelper;
use App\Services\Kpi\KsbeKpiMonthlyService;
use App\Services\Kpi\KsbeLeadershipIndexService;
use Illuminate\Support\Facades\Schema;
use App\Models\User;


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

    
    public function scopeAoCodesForLeader(\App\Models\User $leader, $asOfDate): array
    {
        $asOf = Carbon::parse($asOfDate)->toDateString();

        // 1) ambil user_id bawahan yang aktif pada tanggal asOf
        $subUserIds = DB::table('org_assignments')
            ->where('leader_id', $leader->id)
            ->where('is_active', 1)
            ->whereDate('effective_from', '<=', $asOf)
            ->where(function ($q) use ($asOf) {
                $q->whereNull('effective_to')
                ->orWhereDate('effective_to', '>=', $asOf);
            })
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        if (empty($subUserIds)) return [];

        // 2) translate user_id -> ao_code dari tabel users
        return DB::table('users')
            ->whereIn('id', $subUserIds)
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '')
            ->pluck('ao_code')
            ->map(fn($x) => str_pad(trim((string)$x), 6, '0', STR_PAD_LEFT))
            ->unique()
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
        $me = auth()->user();
        abort_unless($me, 403);

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
        if (!in_array($role, ['AO', 'SO', 'RO', 'FE', 'BE','KSBE'], true)) $role = 'AO';

        $startYtd = null;
        $endYtd   = null;

        // kalau kamu pakai akumulasi otomatis untuk TLUM/SO/RO, set di sini
        if (!empty($periodYm)) {
            // contoh: YTD dari 01 Jan tahun period sampai akhir bulan period
            $end = \Carbon\Carbon::parse($periodYm . '-01')->endOfMonth();
            $start = $end->copy()->startOfYear();

            $startYtd = $start->toDateString();
            $endYtd   = $end->toDateString();
        }

        // ==========================================================
        // RO (auto mode: bulan ini realtime, bulan lalu ke bawah EOM)
        // + AKUMULASI (YTD s/d bulan terpilih)
        // ==========================================================
        if ($role === 'RO') {

            $mode = $this->resolveRoMode($period); // realtime | eom

            $weights = [
                'repayment' => 0.40,
                'topup'     => 0.20,
                'noa'       => 0.10,
                'dpk'       => 0.30,
            ];

            $leader = auth()->user();
            $leaderRole = $this->leaderRoleValue($leader);

            // $scopeAoCodes = $this->scopeAoCodesForLeader($leader, $periodDate);
            $latestDataDate = DB::table('kpi_os_daily_aos')->max('position_date');
            $latestDataDate = $latestDataDate ? \Carbon\Carbon::parse($latestDataDate) : now();
            $periodCarbon = \Carbon\Carbon::parse($periodDate)->startOfMonth();
            $asOfDate = $mode === 'realtime'
            ? $latestDataDate->toDateString()
            : $periodCarbon->copy()->endOfMonth()->toDateString();

            $scopeAoCodes = $this->scopeAoCodesForLeader($leader, $asOfDate);

            $isLeader = in_array($leaderRole, ['TLRO','KSLU','KSLR','KSBE','KSFE','KBL','PE','DIR','KOM','DIREKSI'], true);

            // =========================
            // AKUMULASI RANGE (YTD)
            // =========================
            $endMonth   = \Carbon\Carbon::parse($periodDate)->startOfMonth(); // contoh: 2026-02-01
            $startMonth = $endMonth->copy()->startOfYear();                  // 2026-01-01

            // bulan terpilih pakai realtime hanya kalau itu bulan berjalan
            $isEndMonthCurrent = $endMonth->equalTo(now()->startOfMonth());
            $endMonthMode      = $isEndMonthCurrent ? 'realtime' : 'eom';

            // =========================
            // LABEL RANGE (YTD) - end date
            // - realtime: pakai latest position_date (biar tidak misleading)
            // - eom     : pakai endOfMonth period tsb
            // =========================
            $startYtdDate = $startMonth->toDateString(); // 2026-01-01
            $endYtdDate   = $endMonth->copy()->endOfMonth()->toDateString(); // default EOM label

            if ($endMonthMode === 'realtime') {

                // default: pakai sumber yang kamu anggap paling valid
                // opsi A: KPI harian (kalau tabel ini memang update tiap hari)
                // $latestPos = DB::table('kpi_os_daily_aos')->max('position_date');

                // opsi B: loan_accounts (sesuai statement kamu)
                $latestPos = DB::table('loan_accounts')->max('position_date');

                if ($latestPos) {
                    $endYtdDate = \Carbon\Carbon::parse($latestPos)->toDateString();
                }
            }

            // =========================
            // BASE USERS (RO)
            // =========================
            $usersQ = DB::table('users as u')
                ->whereRaw("UPPER(TRIM(u.level)) = 'RO'")
                ->whereNotNull('u.ao_code')
                ->whereRaw("TRIM(u.ao_code) <> ''");

            if ($isLeader && !empty($scopeAoCodes)) {
                $usersQ->whereIn('u.ao_code', array_values($scopeAoCodes));
            }

            // =========================
            // SUBQUERY KPI RANGE (aggregate per AO)
            // - month < endMonth => eom
            // - month = endMonth => endMonthMode (realtime jika bulan ini, else eom)
            // =========================
            $kpiAgg = DB::table('kpi_ro_monthly as k')
                ->whereBetween('k.period_month', [$startMonth->toDateString(), $endMonth->toDateString()])
                ->where(function ($w) use ($endMonth, $endMonthMode) {
                    $w->where(function ($q) use ($endMonth) {
                        $q->where('k.period_month', '<', $endMonth->toDateString())
                        ->where('k.calc_mode', '=', 'eom');
                    })->orWhere(function ($q) use ($endMonth, $endMonthMode) {
                        $q->where('k.period_month', '=', $endMonth->toDateString())
                        ->where('k.calc_mode', '=', $endMonthMode);
                    });
                })
                ->groupBy('k.ao_code')
                ->selectRaw("
                    k.ao_code,

                    -- akumulasi (YTD)
                    SUM(COALESCE(k.topup_realisasi,0)) as topup_realisasi,
                    SUM(COALESCE(k.noa_realisasi,0)) as noa_realisasi,

                    -- DPK komponen
                    SUM(COALESCE(k.dpk_migrasi_count,0)) as dpk_migrasi_count,
                    SUM(COALESCE(k.dpk_migrasi_os,0)) as dpk_migrasi_os,
                    SUM(COALESCE(k.dpk_total_os_akhir,0)) as dpk_total_os_akhir,

                    -- RR komponen (lebih valid untuk akumulasi)
                    SUM(COALESCE(k.repayment_total_os,0))  as repayment_total_os,
                    SUM(COALESCE(k.repayment_os_lancar,0)) as repayment_os_lancar,

                    -- meta topup (akumulasi)
                    SUM(COALESCE(k.topup_cif_count,0)) as topup_cif_count,
                    SUM(COALESCE(k.topup_cif_new_count,0)) as topup_cif_new_count,
                    MAX(COALESCE(k.topup_max_cif_amount,0)) as topup_max_cif_amount,

                    -- baseline: kalau ada 1 bulan gagal baseline, flag jadi 0
                    MIN(COALESCE(k.baseline_ok,0)) as baseline_ok
                ");

            // =========================
            // AKUMULASI TARGET (Jan s/d periodStart) (target disimpan tgl 1)
            // =========================
            $targetStart = $startYtdDate;
            $targetEnd   = $endMonth->toDateString(); // 1st day of selected month

            $targetMap = DB::table('kpi_ro_targets')
                ->whereBetween('period', [$targetStart, $targetEnd])
                ->selectRaw("
                    ao_code,
                    SUM(COALESCE(target_topup,0)) as target_topup_acc,
                    SUM(COALESCE(target_noa,0))   as target_noa_acc,
                    MAX(COALESCE(target_rr_pct,0)) as target_rr_pct,
                    MAX(COALESCE(target_dpk_pct,0)) as target_dpk_pct
                ")
                ->groupBy('ao_code')
                ->get()
                ->keyBy('ao_code');

            $rows = $usersQ
                ->leftJoinSub($kpiAgg, 'ka', function ($j) {
                    $j->on('ka.ao_code', '=', 'u.ao_code');
                })
                ->selectRaw("
                    u.id as user_id, u.name, u.ao_code, u.level,

                    COALESCE(ka.topup_realisasi,0) as topup_realisasi,
                    COALESCE(ka.noa_realisasi,0) as noa_realisasi,

                    COALESCE(ka.dpk_migrasi_count,0) as dpk_migrasi_count,
                    COALESCE(ka.dpk_migrasi_os,0) as dpk_migrasi_os,
                    COALESCE(ka.dpk_total_os_akhir,0) as dpk_total_os_akhir,

                    COALESCE(ka.repayment_total_os,0) as repayment_total_os,
                    COALESCE(ka.repayment_os_lancar,0) as repayment_os_lancar,

                    COALESCE(ka.topup_cif_count,0) as topup_cif_count,
                    COALESCE(ka.topup_cif_new_count,0) as topup_cif_new_count,
                    COALESCE(ka.topup_max_cif_amount,0) as topup_max_cif_amount,

                    COALESCE(ka.baseline_ok,0) as baseline_ok
                ")
                ->orderBy('u.name')
                ->get();

            $periodDate = $periodDate ?? ($periodYmd ?? null);
            if (!$periodDate) {
                $raw = trim((string)request('period', ''));
                $periodDate = $raw
                    ? (\Carbon\Carbon::parse($raw)->startOfMonth()->toDateString())
                    : now()->startOfMonth()->toDateString();
            }

            $manualNoaMap = DB::table('kpi_ro_manual_actuals')
                ->whereDate('period', $periodDate)
                ->get()
                ->keyBy(fn($m) => str_pad(trim((string)$m->ao_code), 6, '0', STR_PAD_LEFT));

                logger()->info('ROWS count', ['cnt' => $rows->count()]);
                logger()->info('AO unique', ['cnt' => $rows->pluck('ao_code')->map(fn($x)=>str_pad(trim($x),6,'0',STR_PAD_LEFT))->unique()->count()]);

           // =========================
            // MAP -> HITUNG ACH, SCORE, PI
            // =========================
            $items = $rows->map(function ($r) use ($weights, $targetMap, $manualNoaMap) {

                // =========================
                // >>> INI ISI MAP UTUH PUNYAMU (JANGAN DIHAPUS)
                // =========================
                $ao = str_pad(trim((string)$r->ao_code), 6, '0', STR_PAD_LEFT);

                $tg = $targetMap->get($ao);

                $targetTopup = (float)($tg->target_topup_acc ?? 0);
                $targetNoa   = (int)($tg->target_noa_acc ?? 0);

                // RR actual
                $totalOs  = (float)($r->repayment_total_os ?? 0);
                $osLancar = (float)($r->repayment_os_lancar ?? 0);
                $rrActual = $totalOs > 0 ? round(($osLancar / $totalOs) * 100.0, 2) : 0.0;
                $scoreRr  = \App\Services\Kpi\KpiScoreHelper::scoreBand1to6ByActualRr($rrActual);

                // TopUp vs target
                $topupReal  = (float)($r->topup_realisasi ?? 0);
                $achTopup   = \App\Services\Kpi\KpiScoreHelper::achievementPct($topupReal, $targetTopup);
                $scoreTopup = \App\Services\Kpi\KpiScoreHelper::scoreBand1to6($achTopup);

                // NOA manual vs target
                $manual  = $manualNoaMap->get($ao);
                $noaReal = (int)($manual->noa_pengembangan ?? 0);
                $achNoa   = \App\Services\Kpi\KpiScoreHelper::achievementPct((float)$noaReal, (float)$targetNoa);
                $scoreNoa = \App\Services\Kpi\KpiScoreHelper::scoreBand1to6($achNoa);

                // DPK actual reverse
                $sumMigrasiOs = (float)($r->dpk_migrasi_os ?? 0);
                $sumOsAkhir   = (float)($r->dpk_total_os_akhir ?? 0);
                $dpkActual    = $sumOsAkhir > 0 ? round(($sumMigrasiOs / $sumOsAkhir) * 100.0, 2) : 0.0;
                $scoreDpk     = \App\Services\Kpi\KpiScoreHelper::scoreBand1to6ByActualDpkReverse($dpkActual);

                // PI
                $piRepay = round($scoreRr    * $weights['repayment'], 2);
                $piTopup = round($scoreTopup * $weights['topup'], 2);
                $piNoa   = round($scoreNoa   * $weights['noa'], 2);
                $piDpk   = round($scoreDpk   * $weights['dpk'], 2);
                $piTotal = round($piRepay + $piTopup + $piNoa + $piDpk, 2);

                return (object) array_merge((array)$r, [
                    'ao_code' => $ao,

                    'repayment_pct_display' => $rrActual,
                    'dpk_pct_display'       => $dpkActual,

                    'noa_is_manual'    => (bool)$manual,
                    'noa_manual_notes' => $manual->notes ?? null,
                    'noa_realisasi'    => $noaReal,

                    'target_topup' => $targetTopup,
                    'target_noa'   => $targetNoa,

                    'ach_rr'    => $rrActual,
                    'ach_dpk'   => $dpkActual,
                    'ach_topup' => $achTopup,
                    'ach_noa'   => $achNoa,

                    'repayment_score' => $scoreRr,
                    'topup_score'     => $scoreTopup,
                    'noa_score'       => $scoreNoa,
                    'dpk_score'       => $scoreDpk,

                    'pi_repayment' => $piRepay,
                    'pi_topup'     => $piTopup,
                    'pi_noa'       => $piNoa,
                    'pi_dpk'       => $piDpk,
                    'pi_total'     => $piTotal,
                ]);
            });

            // penting: tetap kirim items ke view
            return view('kpi.marketing.sheet', [
                'role'     => $role,
                'periodYm' => $periodYm,
                'period'   => $period,
                'mode'     => $mode,
                'weights'  => $weights,
                'items'    => $items,
                'tlRecap'  => $tlRecap ?? null,

                'startYtd' => $startYtdDate,
                'endYtd'   => $endYtdDate, // pakai label endYtdDate yang kamu hitung (latest position_date kalau realtime)
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

            // =========================
            // ✅ AKUMULASI Jan..periode
            // =========================
            $p = \Carbon\Carbon::parse($periodDate);                 // periodDate = YYYY-MM-01 (startOfMonth)
            $startYtd = $p->copy()->startOfYear()->toDateString();   // 01 Jan YYYY
            $endYtd   = $p->copy()->startOfMonth()->toDateString();  // 01 <bulan> YYYY (karena datamu per bulan pakai startOfMonth)

            // --- Subquery: Target akumulasi (sum Jan..periode) per user ---
            $subTargets = DB::table('kpi_so_targets')
                ->whereBetween('period', [$startYtd, $endYtd])
                ->selectRaw("
                    user_id,
                    SUM(COALESCE(target_os_disbursement,0))  as target_os_disbursement,
                    SUM(COALESCE(target_noa_disbursement,0)) as target_noa_disbursement,
                    MAX(COALESCE(target_rr,100))             as target_rr,
                    SUM(COALESCE(target_activity,0))         as target_activity
                ")
                ->groupBy('user_id');

            // --- Subquery: Actual akumulasi (sum Jan..periode) per user ---
            // RR: hitung weighted average berbasis os_disbursement_raw (kalau ada), fallback AVG(rr_pct)
            $subActuals = DB::table('kpi_so_monthlies')
                ->whereBetween('period', [$startYtd, $endYtd])
                ->selectRaw("
                    user_id,
                    SUM(COALESCE(os_disbursement,0))      as os_disbursement,
                    SUM(COALESCE(os_disbursement_raw,0))  as os_disbursement_raw,
                    SUM(COALESCE(os_adjustment,0))        as os_adjustment,
                    SUM(COALESCE(noa_disbursement,0))     as noa_disbursement,
                    SUM(COALESCE(activity_actual,0))      as activity_actual,

                    -- RR weighted avg:
                    CASE
                    WHEN SUM(CASE WHEN COALESCE(os_disbursement_raw,0) > 0 THEN COALESCE(os_disbursement_raw,0) ELSE 0 END) > 0
                        THEN
                        SUM( (COALESCE(rr_pct,0) * CASE WHEN COALESCE(os_disbursement_raw,0) > 0 THEN COALESCE(os_disbursement_raw,0) ELSE 0 END) )
                        / SUM( CASE WHEN COALESCE(os_disbursement_raw,0) > 0 THEN COALESCE(os_disbursement_raw,0) ELSE 0 END )
                    ELSE
                        AVG(COALESCE(rr_pct,0))
                    END as rr_pct
                ")
                ->groupBy('user_id');

            // --- Query utama: join user + hasil akumulasi ---
            $rows = DB::table('users as u')
                ->leftJoinSub($subTargets, 't', function ($j) {
                    $j->on('t.user_id', '=', 'u.id');
                })
                ->leftJoinSub($subActuals, 'm', function ($j) {
                    $j->on('m.user_id', '=', 'u.id');
                })
                ->where('u.level', 'SO')
                ->select([
                    'u.id as user_id','u.name','u.ao_code','u.level',

                    // target akumulasi
                    't.target_os_disbursement',
                    't.target_noa_disbursement',
                    't.target_rr',
                    't.target_activity',

                    // actual akumulasi
                    'm.os_disbursement',
                    'm.os_disbursement_raw',
                    'm.os_adjustment',
                    'm.noa_disbursement',
                    'm.rr_pct',
                    'm.activity_actual',
                ])
                ->orderBy('u.name')
                ->get();

            $items = $rows->map(function ($r) use ($weights, $startYtd, $endYtd) {
                // achievement
                $achOs  = $this->pct($r->os_disbursement ?? 0, $r->target_os_disbursement ?? 0);
                $achNoa = $this->pct($r->noa_disbursement ?? 0, $r->target_noa_disbursement ?? 0);
                $achAct = $this->pct($r->activity_actual ?? 0, $r->target_activity ?? 0);

                $targetRr = (float)($r->target_rr ?? 100);
                $rrPct    = (float)($r->rr_pct ?? 0);
                $achRr    = $targetRr > 0 ? round(($rrPct / $targetRr) * 100, 2) : 0;

                // =========================
                // ✅ Re-score berdasarkan AKUMULASI
                // =========================
                // OS & NOA: band achievement 1..6 (0-24=1, 25-49=2, 50-74=3, 75-99=4, 100=5, >100=6)
                $scoreOs  = \App\Services\Kpi\KpiScoreHelper::scoreBand1to6((float)$achOs);
                $scoreNoa = \App\Services\Kpi\KpiScoreHelper::scoreBand1to6((float)$achNoa);

                // RR SO: rubric khusus SO
                $scoreRr  = \App\Services\Kpi\KpiScoreHelper::scoreFromRepaymentRateSo6((float)$rrPct);

                // Activity SO: Handling Komunitas rubric SO (0=>1, 1=>4, 2=>5, >=3=>6)
                // Jika activity kamu memang "komunitas", pakai ini. Kalau activity itu KPI lain yg target-based, ganti ke scoreBand1to6($achAct).
                $scoreAct = \App\Services\Kpi\KpiScoreHelper::scoreFromHandlingKomunitasSo6((int)($r->activity_actual ?? 0));

                // PI
                $piOs  = round($scoreOs  * $weights['os'], 2);
                $piNoa = round($scoreNoa * $weights['noa'], 2);
                $piRr  = round($scoreRr  * $weights['rr'], 2);
                $piAct = round($scoreAct * $weights['activity'], 2);

                $totalPi = round($piOs + $piNoa + $piRr + $piAct, 2);

                return (object) array_merge((array)$r, [
                    // achievement for blade
                    'ach_os'       => $achOs,
                    'ach_noa'      => $achNoa,
                    'ach_rr'       => $achRr,
                    'ach_activity' => $achAct,
                    'target_rr'    => $targetRr,

                    // scores (akumulasi)
                    'score_os'       => $scoreOs,
                    'score_noa'      => $scoreNoa,
                    'score_rr'       => $scoreRr,
                    'score_activity' => $scoreAct,

                    // pi
                    'pi_os'       => $piOs,
                    'pi_noa'      => $piNoa,
                    'pi_rr'       => $piRr,
                    'pi_activity' => $piAct,
                    'pi_total'    => $totalPi,

                    // meta (optional)
                    'startYtd' => $startYtd,
                    'endYtd'   => $endYtd,
                ]);
            });

            return view('kpi.marketing.sheet', [
                'role'     => $role,
                'periodYm' => $periodYm,
                'period'   => $period,

                // ✅ bobot & data
                'weights'  => $weights,
                'items'    => $items,

                // ✅ label akumulasi (kalau blade kamu sudah pakai)
                'startYtd' => $startYtd,
                'endYtd'   => $endYtd,
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
                'tlRecap' => $res['tlfeRecap'] ?? ($res['tlFeRecap'] ?? null),

                // ✅ tambahan untuk label akumulasi (dan konsistensi dgn RO)
                'startYtd'  => $res['startYtd'] ?? null,
                'endYtd'    => $res['endYtd'] ?? null,
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

        // ========= KSBE =========
        if ($role === 'KSBE') {

            $authUser = auth()->user();
            abort_unless($authUser, 403);

            $ksbe = app(KsbeKpiMonthlyService::class)->buildForPeriod($periodYm, $me);

            $ksbe = app(KsbeLeadershipIndexService::class)->buildAndStore($periodYm, $me, $ksbe);
            $ksbeAi = app(\App\Services\Kpi\KsbeLeadershipAiEngine::class)->build($ksbe);

           

            return view('kpi.marketing.sheet', [
                'role' => 'KSBE',
                'periodYm' => $periodYm,
                'period' => $ksbe['period'],
                'mode' => $ksbe['mode'],
                'leader'     => $ksbe['leader'] ?? ['id'=>$authUser->id,'name'=>$authUser->name,'level'=>'KSBE'],
                'weights'    => $ksbe['weights'] ?? [],
                'recap'      => $ksbe['recap'] ?? [],
                'items'      => $ksbe['items'] ?? collect(),
                'leadership' => $ksbe['leadership'] ?? [],
                'ksbe'   => $ksbe,
                'ksbeAi' => $ksbeAi,
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

        // =========================================================
        // ✅ NEW: MODE AKUMULASI (YTD / Jan s.d periode)
        // - Feb = Jan+Feb, Mar = Jan+Feb+Mar, dst
        // - target & actual KPI SUM (kecuali RR dihitung weighted ratio)
        // =========================================================
        $startYtd = Carbon::parse($periodDate)->startOfYear()->toDateString();

        // default: akhir bulan period (buat label aman)
        $monthEnd = Carbon::parse($periodDate)->endOfMonth()->toDateString();
        $endYtd   = $monthEnd;

        // kalau bulan berjalan, tampilkan last position_date (capped <= monthEnd)
        $isCurrentMonth = Carbon::parse($periodDate)->startOfMonth()->equalTo(now()->startOfMonth());

        if ($isCurrentMonth) {
            $latest = null;

            if (Schema::hasTable('loan_accounts') && Schema::hasColumn('loan_accounts', 'position_date')) {
                $latest = DB::table('loan_accounts')->max('position_date');
            }

            if (!$latest && Schema::hasTable('kpi_os_daily_aos') && Schema::hasColumn('kpi_os_daily_aos', 'position_date')) {
                $latest = DB::table('kpi_os_daily_aos')->max('position_date');
            }

            if ($latest) {
                $latestDate = Carbon::parse($latest)->toDateString();
                $endYtd = min($latestDate, $monthEnd); // guard
            }
        }

        // helper local skor 1..6 berbasis achievement pct (0..∞)
        // (dipakai untuk NOA/OS/Community/Daily; RR tetap pakai helper RR)
        $scoreFromPct6 = function (float $pct): int {
            if ($pct < 25) return 1;
            if ($pct < 50) return 2;
            if ($pct < 75) return 3;
            if ($pct < 100) return 4;
            if ($pct < 125) return 5;
            return 6;
        };

        // Subquery ACTUAL YTD
        $subActualYtd = \Illuminate\Support\Facades\DB::table('kpi_ao_monthlies')
            ->where('scheme', 'AO_UMKM')
            ->whereBetween('period', [$startYtd, $endYtd])
            ->groupBy('user_id')
            ->selectRaw("
                user_id,
                SUM(os_disbursement)        as os_disbursement,
                SUM(noa_disbursement)       as noa_disbursement,
                SUM(community_actual)       as community_actual,
                SUM(daily_report_actual)    as daily_report_actual,
                SUM(rr_os_total)            as rr_os_total,
                SUM(rr_os_current)          as rr_os_current
            ");

        // Subquery TARGET YTD
        $subTargetYtd = \Illuminate\Support\Facades\DB::table('kpi_ao_targets')
            ->whereBetween('period', [$startYtd, $endYtd])
            ->groupBy('user_id')
            ->selectRaw("
                user_id,
                SUM(target_os_disbursement)     as target_os_disbursement,
                SUM(target_noa_disbursement)    as target_noa_disbursement,
                MAX(target_rr)                  as target_rr,
                SUM(target_community)           as target_community,
                SUM(target_daily_report)        as target_daily_report
            ");

        // ====== TLUM SCOPE (untuk tabel TLUM + ranking) ======
        $me = $request->user();
        $tlum = null;
        $tlumRowsRank = collect();

        try {
            $roleAliases = ['tl', 'tlum', 'tl um', 'tl-um', 'tl_um', 'tl umkm', 'tl-umkm', 'tl_umkm'];

            $subUserIds = \Illuminate\Support\Facades\DB::table('org_assignments')
                ->where('leader_id', (int)($me?->id ?? 0))
                ->where('is_active', 1)
                ->whereIn(\Illuminate\Support\Facades\DB::raw('LOWER(TRIM(leader_role))'), $roleAliases)
                ->whereDate('effective_from', '<=', $periodDate)
                ->where(function ($q) use ($periodDate) {
                    $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $periodDate);
                })
                ->pluck('user_id')
                ->unique()
                ->values()
                ->all();

            $aoUserIds = empty($subUserIds) ? [] : \Illuminate\Support\Facades\DB::table('users')
                ->whereIn('id', $subUserIds)
                ->where('level', 'AO')
                ->pluck('id')
                ->unique()
                ->values()
                ->all();

            // =========================================================
            // ✅ BASE RANK Q: pakai agregasi YTD (Jan..periode)
            // =========================================================
            $baseRankQ = \Illuminate\Support\Facades\DB::query()
                ->fromSub($subActualYtd, 'm')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->leftJoinSub($subTargetYtd, 't', function ($j) {
                    $j->on('t.user_id', '=', 'm.user_id');
                })
                ->where('u.level', 'AO');

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
                    'm.rr_os_total',
                    'm.rr_os_current',
                    'm.community_actual',
                    'm.daily_report_actual',
                ])
                ->orderBy('u.name')
                ->get();

            // =========================================================
            // ✅ HITUNG ULANG pct/score/pi DARI AGREGAT YTD
            // =========================================================
            $tlumRowsRank = $rankRows->map(function ($r) use ($weightsUmkm, $scoreFromPct6) {

                $targetNoa = (float)($r->target_noa_disbursement ?? 0);
                $targetOs  = (float)($r->target_os_disbursement ?? 0);
                $targetCom = (float)($r->target_community ?? 0);
                $targetDay = (float)($r->target_daily_report ?? 0);

                $actNoa = (float)($r->noa_disbursement ?? 0);
                $actOs  = (float)($r->os_disbursement ?? 0);
                $actCom = (float)($r->community_actual ?? 0);
                $actDay = (float)($r->daily_report_actual ?? 0);

                $rrTotal   = (float)($r->rr_os_total ?? 0);
                $rrCurrent = (float)($r->rr_os_current ?? 0);
                $rrPct     = $rrTotal > 0 ? round(100.0 * $rrCurrent / $rrTotal, 2) : 0.0;

                $noaPct = \App\Services\Kpi\KpiScoreHelper::safePct($actNoa, $targetNoa);
                $osPct  = \App\Services\Kpi\KpiScoreHelper::safePct($actOs,  $targetOs);
                $comPct = \App\Services\Kpi\KpiScoreHelper::safePct($actCom, $targetCom);
                $dayPct = \App\Services\Kpi\KpiScoreHelper::safePct($actDay, $targetDay);

                // score 1..6
                $scoreNoa = $scoreFromPct6((float)$noaPct);
                $scoreOs  = $scoreFromPct6((float)$osPct);
                $scoreCom = $scoreFromPct6((float)$comPct);
                $scoreDay = $scoreFromPct6((float)$dayPct);

                // RR pakai helper khusus (kalau helper kamu memang 1..6)
                // kalau ternyata helper RR kamu 1..5, nanti kita adjust konsisten 1..6
                $scoreRr  = \App\Services\Kpi\KpiScoreHelper::scoreFromRepaymentRateAo6((float)$rrPct);

                $piNoa = round($scoreNoa * $weightsUmkm['noa'], 2);
                $piOs  = round($scoreOs  * $weightsUmkm['os'], 2);
                $piRr  = round($scoreRr  * $weightsUmkm['rr'], 2);
                $piCom = round($scoreCom * $weightsUmkm['community'], 2);
                $piDay = round($scoreDay * $weightsUmkm['daily'], 2);
                $piTot = round($piNoa + $piOs + $piRr + $piCom + $piDay, 2);

                return (object) array_merge((array)$r, [
                    'os_disbursement_pct' => round((float)$osPct, 2),
                    'noa_disbursement_pct' => round((float)$noaPct, 2),
                    'rr_pct' => (float)$rrPct,
                    'community_pct' => round((float)$comPct, 2),
                    'daily_report_pct' => round((float)$dayPct, 2),

                    'score_os' => (int)$scoreOs,
                    'score_noa' => (int)$scoreNoa,
                    'score_rr' => (int)$scoreRr,
                    'score_community' => (int)$scoreCom,
                    'score_daily_report' => (int)$scoreDay,

                    'score_total' => round((float)($scoreNoa + $scoreOs + $scoreRr + $scoreCom + $scoreDay), 2),

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

                // NOTE: TLUM scoring tetap pakai helper TLUM kamu (kalau memang sudah fix)
                $scoreNoaT = \App\Services\Kpi\KpiScoreHelper::scoreBand1to6((float)$noaPct);
                // atau: scoreFromAchievementPct6((float)$noaPct)
                $scoreOsT = \App\Services\Kpi\KpiScoreHelper::scoreBand1to6((float)$osPct);
                $scoreRrT  = \App\Services\Kpi\KpiScoreHelper::scoreFromRepaymentRateAo6($rrWeighted);
                $scoreComT = \App\Services\Kpi\KpiScoreHelper::scoreBand1to6((float)$comPct);

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
                    \Illuminate\Support\Facades\DB::table('kpi_tlum_monthlies')->updateOrInsert(
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
        // =========================================================
        // ✅ Ganti query bulanan -> YTD (Jan..periode)
        // =========================================================
        $rows = \Illuminate\Support\Facades\DB::query()
            ->fromSub($subActualYtd, 'm')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoinSub($subTargetYtd, 't', function ($j) {
                $j->on('t.user_id', '=', 'm.user_id');
            })
            ->where('u.level', 'AO')
            ->select([
                'u.id as user_id','u.name','u.ao_code','u.level',
                \Illuminate\Support\Facades\DB::raw("'AO_UMKM' as scheme"),

                't.target_os_disbursement',
                't.target_noa_disbursement',
                't.target_rr',
                't.target_community',
                't.target_daily_report',

                'm.os_disbursement',
                'm.noa_disbursement',
                'm.rr_os_total',
                'm.rr_os_current',
                'm.community_actual',
                'm.daily_report_actual',
            ])
            ->orderBy('u.name')
            ->get();

        $items = $rows->map(function ($r) use ($weightsUmkm, $scoreFromPct6) {

            $targetNoa = (float)($r->target_noa_disbursement ?? 0);
            $targetOs  = (float)($r->target_os_disbursement ?? 0);
            $targetCom = (float)($r->target_community ?? 0);
            $targetDay = (float)($r->target_daily_report ?? 0);

            $actNoa = (float)($r->noa_disbursement ?? 0);
            $actOs  = (float)($r->os_disbursement ?? 0);
            $actCom = (float)($r->community_actual ?? 0);
            $actDay = (float)($r->daily_report_actual ?? 0);

            $rrTotal   = (float)($r->rr_os_total ?? 0);
            $rrCurrent = (float)($r->rr_os_current ?? 0);
            $rrPct     = $rrTotal > 0 ? round(100.0 * $rrCurrent / $rrTotal, 2) : 0.0;

            $noaPct = \App\Services\Kpi\KpiScoreHelper::safePct($actNoa, $targetNoa);
            $osPct  = \App\Services\Kpi\KpiScoreHelper::safePct($actOs,  $targetOs);
            $comPct = \App\Services\Kpi\KpiScoreHelper::safePct($actCom, $targetCom);
            $dayPct = \App\Services\Kpi\KpiScoreHelper::safePct($actDay, $targetDay);

            $scoreNoa = $scoreFromPct6((float)$noaPct);
            $scoreOs  = $scoreFromPct6((float)$osPct);
            $scoreCom = $scoreFromPct6((float)$comPct);
            $scoreDay = $scoreFromPct6((float)$dayPct);
            $scoreRr  = \App\Services\Kpi\KpiScoreHelper::scoreFromRepaymentRateAo6((float)$rrPct);

            $piNoa = round($scoreNoa * $weightsUmkm['noa'], 2);
            $piOs  = round($scoreOs  * $weightsUmkm['os'], 2);
            $piRr  = round($scoreRr  * $weightsUmkm['rr'], 2);
            $piCom = round($scoreCom * $weightsUmkm['community'], 2);
            $piDay = round($scoreDay * $weightsUmkm['daily'], 2);
            $piTot = round($piNoa + $piOs + $piRr + $piCom + $piDay, 2);

            return (object) array_merge((array)$r, [
                'mode' => 'AO_UMKM',

                'os_disbursement_pct' => round((float)$osPct, 2),
                'noa_disbursement_pct' => round((float)$noaPct, 2),
                'rr_pct' => (float)$rrPct,
                'community_pct' => round((float)$comPct, 2),
                'daily_report_pct' => round((float)$dayPct, 2),

                'score_os' => (int)$scoreOs,
                'score_noa' => (int)$scoreNoa,
                'score_rr' => (int)$scoreRr,
                'score_community' => (int)$scoreCom,
                'score_daily_report' => (int)$scoreDay,

                'score_total' => round((float)($scoreNoa + $scoreOs + $scoreRr + $scoreCom + $scoreDay), 2),

                'ach_noa'       => round((float)$noaPct, 2),
                'ach_os'        => round((float)$osPct, 2),
                'ach_rr'        => (float)$rrPct,
                'ach_community' => round((float)$comPct, 2),
                'ach_daily'     => round((float)$dayPct, 2),

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
            $prevPeriod = \Carbon\Carbon::parse($periodDate)->subMonth()->startOfMonth()->toDateString();

            $cur = \Illuminate\Support\Facades\DB::table('kpi_tlum_monthlies')
                ->where('period', $periodDate)
                ->where('tlum_user_id', (int)$me->id)
                ->first();

            $prev = \Illuminate\Support\Facades\DB::table('kpi_tlum_monthlies')
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

            // ✅ optional info untuk blade (biar jelas ini mode akumulasi)
            'startYtd' => $startYtd,
            'endYtd'   => $endYtd,
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
