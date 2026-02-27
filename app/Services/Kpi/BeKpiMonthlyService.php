<?php

namespace App\Services\Kpi;

use App\Models\KpiBeMonthly;
use App\Models\KpiBeTarget;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BeKpiMonthlyService
{
    // bobot tetap
    private float $wOs    = 0.50;
    private float $wNoa   = 0.10;
    private float $wBunga = 0.20;
    private float $wDenda = 0.20;

    public function buildForPeriod(string $periodYm, $authUser): array
    {
        // parse aman
        try {
            $period = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        } catch (\Throwable $e) {
            $periodYm = now()->format('Y-m');
            $period   = now()->startOfMonth();
        }

        $periodDate = $period->toDateString(); // YYYY-MM-01

        // =====================================================
        // AKUMULASI RANGE (YTD) - sama seperti FE
        // =====================================================
        $endMonth   = Carbon::parse($periodDate)->startOfMonth();
        $startMonth = $endMonth->copy()->startOfYear();

        $isEndMonthCurrent = $endMonth->equalTo(now()->startOfMonth());
        $endMonthMode      = $isEndMonthCurrent ? 'realtime' : 'eom';

        $startYtd = $startMonth->toDateString();
        $endYtd   = $endMonth->copy()->endOfMonth()->toDateString();

        // =====================================================
        // scope (sementara sesuai keputusanmu)
        // =====================================================
        $scopeUserIds = $this->resolveScopeUserIds($authUser, $periodDate);

        $users = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereIn('id', $scopeUserIds)
            ->whereRaw("UPPER(TRIM(level)) = 'BE'")
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code) <> ''")
            ->orderBy('name')
            ->get();

        if ($users->isEmpty()) {
            return [
                'period'   => $period,
                'mode'     => $endMonthMode, // realtime/eom (end month)
                'weights'  => $this->weights(),
                'items'    => collect(),
                'startYtd' => $startYtd,
                'endYtd'   => $endYtd,
            ];
        }

        $beIds = $users->pluck('id')->map(fn($x)=>(int)$x)->values()->all();

        // =====================================================
        // TARGET AGG (YTD): sum target per BE
        // =====================================================
        $targetAgg = DB::table('kpi_be_targets as t')
            ->whereBetween('t.period', [$startMonth->toDateString(), $endMonth->toDateString()])
            ->whereIn('t.be_user_id', $beIds)
            ->groupBy('t.be_user_id')
            ->selectRaw("
                t.be_user_id,
                SUM(COALESCE(t.target_os_selesai,0))    as t_os,
                SUM(COALESCE(t.target_noa_selesai,0))   as t_noa,
                SUM(COALESCE(t.target_bunga_masuk,0))   as t_bunga,
                SUM(COALESCE(t.target_denda_masuk,0))   as t_denda
            ");

        // =====================================================
        // ACTUAL AGG (YTD): ambil dari kpi_be_monthlies (frozen)
        // - bulan < endMonth: eom saja
        // - endMonth: eom atau realtime sesuai kondisi
        // =====================================================
        $actualAgg = DB::table('kpi_be_monthlies as k')
            ->whereBetween('k.period', [$startMonth->toDateString(), $endMonth->toDateString()])
            ->whereIn('k.be_user_id', $beIds)
            ->groupBy('k.be_user_id')
            ->selectRaw("
                k.be_user_id,
                SUM(COALESCE(k.actual_os_selesai,0)) as a_os,
                SUM(COALESCE(k.actual_noa_selesai,0)) as a_noa,
                SUM(COALESCE(k.actual_bunga_masuk,0)) as a_bunga,
                SUM(COALESCE(k.actual_denda_masuk,0)) as a_denda,
                1 as baseline_ok
            ");

        // =====================================================
        // JOIN users + targetAgg + actualAgg
        // =====================================================
        $rows = DB::table('users as u')
            ->whereIn('u.id', $beIds)
            ->leftJoinSub($targetAgg, 'ta', fn($j) => $j->on('ta.be_user_id', '=', 'u.id'))
            ->leftJoinSub($actualAgg, 'aa', fn($j) => $j->on('aa.be_user_id', '=', 'u.id'))
            ->selectRaw("
                u.id as be_user_id,
                u.name,
                u.ao_code,

                COALESCE(ta.t_os,0)    as t_os,
                COALESCE(ta.t_noa,0)   as t_noa,
                COALESCE(ta.t_bunga,0) as t_bunga,
                COALESCE(ta.t_denda,0) as t_denda,

                COALESCE(aa.a_os,0)    as a_os,
                COALESCE(aa.a_noa,0)   as a_noa,
                COALESCE(aa.a_bunga,0) as a_bunga,
                COALESCE(aa.a_denda,0) as a_denda,

                COALESCE(aa.baseline_ok,1) as baseline_ok
            ")
            ->orderBy('u.name')
            ->get();

        // =====================================================
        // INFO NPL (Stock) untuk YTD:
        // - prev: snapshot prevSnap dari startMonth
        // - now : snapshot endMonth
        // =====================================================
        $infoByAo = $this->buildNplInfoStockForYtd($users, $startMonth, $endMonth);

        // =====================================================
        // scoring helpers
        // =====================================================
        $scorePercent = function (?float $ratio): int {
            if ($ratio === null) return 1;
            if ($ratio < 0.25) return 1;
            if ($ratio < 0.50) return 2;
            if ($ratio < 0.75) return 3;
            if ($ratio < 1.00) return 4;
            if ($ratio <= 1.0000001) return 5;
            return 6;
        };

        $scoreNoaByTarget = function (int $actual, int $target) use ($scorePercent): int {
            if ($target <= 0) {
                return ($actual > 0) ? 6 : 1;
            }
            $ratio = $actual / max(1, $target);
            return $scorePercent($ratio);
        };

        // =====================================================
        // map final items (format konsisten dengan sheet)
        // =====================================================
        $items = $rows->map(function ($r) use ($scorePercent, $scoreNoaByTarget, $endMonthMode, $infoByAo) {

            $ao = (string)($r->ao_code ?? '');

            $tOs    = (float)($r->t_os ?? 0);
            $tNoa   = (int)  ($r->t_noa ?? 0);
            $tBunga = (float)($r->t_bunga ?? 0);
            $tDenda = (float)($r->t_denda ?? 0);

            $aOs    = (float)($r->a_os ?? 0);
            $aNoa   = (int)  ($r->a_noa ?? 0);
            $aBunga = (float)($r->a_bunga ?? 0);
            $aDenda = (float)($r->a_denda ?? 0);

            $rOs    = $tOs    > 0 ? ($aOs / $tOs)       : null;
            $rBunga = $tBunga > 0 ? ($aBunga / $tBunga) : null;
            $rDenda = $tDenda > 0 ? ($aDenda / $tDenda) : null;

            $sOs    = $scorePercent($rOs);
            $sNoa   = $scoreNoaByTarget($aNoa, $tNoa); // ✅ NOA ikut target agar konsisten YTD
            $sBunga = $scorePercent($rBunga);
            $sDenda = $scorePercent($rDenda);

            $piOs    = $sOs    * $this->wOs;
            $piNoa   = $sNoa   * $this->wNoa;
            $piBunga = $sBunga * $this->wBunga;
            $piDenda = $sDenda * $this->wDenda;

            $totalPi = $piOs + $piNoa + $piBunga + $piDenda;

            $info = $infoByAo[$ao] ?? ['os_npl_prev'=>0,'os_npl_now'=>0,'net_npl_drop'=>0];

            return [
                'be_user_id' => (int)$r->be_user_id,
                'name'       => (string)($r->name ?? '-'),
                'code'       => $ao,

                'source' => 'ytd',          // ✅ label baru
                'mode'   => $endMonthMode,  // realtime/eom utk endMonth
                'status' => null,

                'baseline_ok' => (int)($r->baseline_ok ?? 1),

                'target' => [
                    'os'    => $tOs,
                    'noa'   => $tNoa,
                    'bunga' => $tBunga,
                    'denda' => $tDenda,
                ],
                'actual' => [
                    'os'    => $aOs,
                    'noa'   => $aNoa,
                    'bunga' => $aBunga,
                    'denda' => $aDenda,

                    // info stock YTD
                    'os_npl_prev'  => (float)$info['os_npl_prev'],
                    'os_npl_now'   => (float)$info['os_npl_now'],
                    'net_npl_drop' => (float)$info['net_npl_drop'],
                ],
                'ach' => [
                    'os'    => $tOs > 0 ? round(($aOs / $tOs) * 100, 2) : 0.0,
                    'noa'   => $tNoa > 0 ? round(($aNoa / max(1,$tNoa)) * 100, 2) : 0.0,
                    'bunga' => $tBunga > 0 ? round(($aBunga / $tBunga) * 100, 2) : 0.0,
                    'denda' => $tDenda > 0 ? round(($aDenda / $tDenda) * 100, 2) : 0.0,
                ],
                'score' => [
                    'os'    => $sOs,
                    'noa'   => $sNoa,
                    'bunga' => $sBunga,
                    'denda' => $sDenda,
                ],
                'pi' => [
                    'os'    => round($piOs, 2),
                    'noa'   => round($piNoa, 2),
                    'bunga' => round($piBunga, 2),
                    'denda' => round($piDenda, 2),
                    'total' => round($totalPi, 2),
                ],
            ];
        });

        // sort desc total PI
        $items = $items->sortByDesc(fn($x) => (float)($x['pi']['total'] ?? 0))->values();

        return [
            'period'   => $period,
            'mode'     => $endMonthMode,
            'weights'  => $this->weights(),
            'items'    => $items,
            'startYtd' => $startYtd,
            'endYtd'   => $endYtd,
        ];
    }

    private function weights(): array
    {
        return [
            'os'    => $this->wOs,
            'noa'   => $this->wNoa,
            'bunga' => $this->wBunga,
            'denda' => $this->wDenda,
        ];
    }

    private function resolveScopeUserIds($authUser, string $periodDate): array
    {
        if (!$authUser) return [];

        $rawLvl = $authUser->level ?? '';
        $lvl = strtoupper(trim((string)($rawLvl instanceof \BackedEnum ? $rawLvl->value : $rawLvl)));

        // KBL global
        $isKbl = method_exists($authUser, 'hasAnyRole') && $authUser->hasAnyRole(['KBL']);
        if ($isKbl || $lvl === 'KBL') {
            return DB::table('users')
                ->whereRaw("UPPER(TRIM(level)) = 'BE'")
                ->pluck('id')->map(fn($x)=>(int)$x)->values()->all();
        }

        // KSBE / KASI: ambil bawahan BE dari org_assignments
        if (in_array($lvl, ['KSBE','KASI'], true)) {
            return DB::table('org_assignments as oa')
                ->join('users as u', 'u.id', '=', 'oa.user_id')
                ->where('oa.leader_id', (int)$authUser->id)
                ->where('oa.is_active', 1)
                ->whereDate('oa.effective_from', '<=', $periodDate)
                ->where(function($q) use ($periodDate) {
                    $q->whereNull('oa.effective_to')
                    ->orWhereDate('oa.effective_to', '>=', $periodDate);
                })
                ->whereRaw("UPPER(TRIM(u.level)) = 'BE'")
                ->pluck('u.id')
                ->map(fn($x)=>(int)$x)->values()->all();
        }

        // BE hanya diri sendiri
        if ($lvl === 'BE') {
            return [(int)$authUser->id];
        }

        return [];
    }

     private function resolveScopeBeUserIdsForKsbe($ksbeUser, string $periodDate): array
    {
        if (!$ksbeUser) return [];

        $role = $ksbeUser->level instanceof \BackedEnum
            ? strtoupper(trim((string)$ksbeUser->level->value))
            : strtoupper(trim((string)$ksbeUser->level));

        if ($role !== 'KSBE') return [];

        $subIds = DB::table('org_assignments as oa')
            ->where('oa.leader_id', (int)$ksbeUser->id)
            ->whereRaw('UPPER(TRIM(oa.leader_role)) = ?', ['KSBE'])
            ->where('oa.is_active', 1)
            ->whereDate('oa.effective_from', '<=', $periodDate)
            ->where(function ($q) use ($periodDate) {
                $q->whereNull('oa.effective_to')
                ->orWhereDate('oa.effective_to', '>=', $periodDate);
            })
            ->pluck('oa.user_id')
            ->map(fn($x) => (int)$x)
            ->unique()
            ->values()
            ->all();

        if (empty($subIds)) return [];

        // pastikan hanya BE
        return DB::table('users')
            ->whereIn('id', $subIds)
            ->whereRaw("UPPER(TRIM(level)) = 'BE'")
            ->pluck('id')
            ->map(fn($x)=>(int)$x)
            ->values()
            ->all();
    }

    /**
     * INFO STOCK untuk YTD:
     * - os_npl_prev: total NPL (kolek 3/4/5) pada snapshot prev dari startMonth
     * - os_npl_now : total NPL (kolek 3/4/5) pada snapshot endMonth
     */
    private function buildNplInfoStockForYtd(Collection $users, Carbon $startMonth, Carbon $endMonth): array
    {
        $startSnap = $startMonth->copy()->subMonthNoOverflow()->toDateString(); // prev of Jan => Dec-01
        $endSnap   = $endMonth->toDateString();                                // end month snapshot = YYYY-MM-01

        $aoCodes = $users->pluck('ao_code')->map(fn($x)=>(string)$x)->values()->all();

        $prev = DB::table('loan_account_snapshots_monthly')
            ->where('snapshot_month', $startSnap)
            ->whereIn('ao_code', $aoCodes)
            ->whereIn('kolek', [3,4,5])
            ->groupBy('ao_code')
            ->selectRaw('ao_code, SUM(outstanding) as os_npl_prev')
            ->pluck('os_npl_prev','ao_code');

        $now = DB::table('loan_account_snapshots_monthly')
            ->where('snapshot_month', $endSnap)
            ->whereIn('ao_code', $aoCodes)
            ->whereIn('kolek', [3,4,5])
            ->groupBy('ao_code')
            ->selectRaw('ao_code, SUM(outstanding) as os_npl_now')
            ->pluck('os_npl_now','ao_code');

        $out = [];
        foreach ($aoCodes as $ao) {
            $p = (float)($prev[$ao] ?? 0);
            $n = (float)($now[$ao] ?? 0);
            $out[$ao] = [
                'os_npl_prev'  => $p,
                'os_npl_now'   => $n,
                'net_npl_drop' => $p - $n,
            ];
        }

        return $out;
    }

    // =========================================================
    // calculateRealtime + calculateOneForSubmit tetap boleh dipakai (untuk recalc month end)
    // =========================================================

    /**
     * Hitung realtime BE untuk sekumpulan user.
     * NOTE PENTING:
     * - snapshot_month di tabel monthly seharusnya pakai YYYY-MM-01 (BUKAN EOM).
     */
    private function calculateRealtime(string $periodYm, Collection $users, Collection $targetsByUserId): array
    {
        $periodStart = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();

        // ✅ snapshot pakai awal bulan
        $currentSnap = $periodStart->toDateString(); // YYYY-MM-01
        $prevSnap    = $periodStart->copy()->subMonthNoOverflow()->toDateString(); // prev YYYY-MM-01

        $monthStart = $currentSnap;
        $monthEndEx = $periodStart->copy()->addMonth()->toDateString();

        $aoCodes = $users->pluck('ao_code')->map(fn($x)=>(string)$x)->values()->all();

        // ===== Recovery OS (OS Selesai) =====
        // RULE BARU:
        // - prev kolek 3/4/5
        // - recovery kalau:
        //   A) cur ada dan cur.kolek jadi 1/2
        //   B) cur NULL -> dihitung hanya kalau CLOSE_TYPE = LUNAS (WO/AYDA tidak)
        $recoveryByAo = DB::table('loan_account_snapshots_monthly as prev')
            ->leftJoin('loan_account_snapshots_monthly as cur', function ($j) use ($currentSnap) {
                $j->on('cur.account_no', '=', 'prev.account_no')
                ->where('cur.snapshot_month', $currentSnap);
            })
            ->leftJoin('loan_account_closures as lc', function ($j) use ($monthStart, $monthEndEx) {
                $j->on('lc.account_no', '=', 'prev.account_no')
                ->whereDate('lc.closed_date', '>=', $monthStart)
                ->whereDate('lc.closed_date', '<',  $monthEndEx);
            })
            ->where('prev.snapshot_month', $prevSnap)
            ->whereIn('prev.ao_code', $aoCodes)
            ->whereIn('prev.kolek', [3,4,5])
            ->where(function ($q) {
                $q->whereIn('cur.kolek', [1,2])
                ->orWhere(function ($qq) {
                    $qq->whereNull('cur.account_no')
                        ->where('lc.close_type', 'LUNAS');
                });
            })
            ->groupBy('prev.ao_code')
            ->selectRaw('prev.ao_code as ao_code, SUM(prev.outstanding) as recovery_os')
            ->pluck('recovery_os', 'ao_code');

        // ===== NPL prev/now (info net drop) =====
        $nplPrevByAo = DB::table('loan_account_snapshots_monthly')
            ->where('snapshot_month', $prevSnap)
            ->whereIn('ao_code', $aoCodes)
            ->whereIn('kolek', [3,4,5])
            ->groupBy('ao_code')
            ->selectRaw('ao_code, SUM(outstanding) as os_npl_prev')
            ->pluck('os_npl_prev','ao_code');

        $nplNowByAo = DB::table('loan_account_snapshots_monthly')
            ->where('snapshot_month', $currentSnap)
            ->whereIn('ao_code', $aoCodes)
            ->whereIn('kolek', [3,4,5])
            ->groupBy('ao_code')
            ->selectRaw('ao_code, SUM(outstanding) as os_npl_now')
            ->pluck('os_npl_now','ao_code');

        // ===== NOA selesai (MATCH RULE BARU Recovery OS) =====
        // RULE BARU (harus sama dengan Recovery OS):
        // - prev kolek 3/4/5
        // - selesai jika:
        //   A) cur ada dan cur.kolek jadi 1/2
        //   B) cur NULL -> dihitung hanya kalau CLOSE_TYPE = LUNAS (WO/AYDA tidak)
        $noaDoneByAo = DB::table('loan_account_snapshots_monthly as prev')
            ->leftJoin('loan_account_snapshots_monthly as cur', function ($j) use ($currentSnap) {
                $j->on('cur.account_no', '=', 'prev.account_no')
                ->where('cur.snapshot_month', $currentSnap);
            })
            ->leftJoin('loan_account_closures as lc', function ($j) use ($monthStart, $monthEndEx) {
                $j->on('lc.account_no', '=', 'prev.account_no')
                ->whereDate('lc.closed_date', '>=', $monthStart)
                ->whereDate('lc.closed_date', '<',  $monthEndEx);
                // optional (kalau mau lebih ketat):
                // ->whereRaw("TRIM(lc.ao_code) = TRIM(prev.ao_code)");
            })
            ->where('prev.snapshot_month', $prevSnap)
            ->whereIn('prev.ao_code', $aoCodes)
            ->whereIn('prev.kolek', [3,4,5])
            ->where(function ($q) {
                $q->whereIn('cur.kolek', [1,2])
                ->orWhere(function ($qq) {
                    $qq->whereNull('cur.account_no')
                        ->where('lc.close_type', 'LUNAS');
                });
            })
            ->groupBy('prev.ao_code')
            ->selectRaw('prev.ao_code as ao_code, COUNT(DISTINCT prev.account_no) as noa_done')
            ->pluck('noa_done','ao_code');

        // ===== Bunga & Denda (join by account_no -> snapshot current month) =====
        $interestByAo = DB::table('loan_installments as li')
            ->join('loan_account_snapshots_monthly as s', function($j) use ($currentSnap) {
                $j->on('s.account_no', '=', 'li.account_no')
                ->where('s.snapshot_month', $currentSnap);
            })
            ->where('li.paid_date','>=',$monthStart)
            ->where('li.paid_date','<',$monthEndEx)
            ->whereIn('s.ao_code', $aoCodes)
            ->groupBy('s.ao_code')
            ->selectRaw('s.ao_code as ao_code, SUM(COALESCE(li.interest_paid,0)) as bunga_masuk')
            ->pluck('bunga_masuk','ao_code');

        $penaltyByAo = DB::table('loan_installments as li')
            ->join('loan_account_snapshots_monthly as s', function($j) use ($currentSnap) {
                $j->on('s.account_no', '=', 'li.account_no')
                ->where('s.snapshot_month', $currentSnap);
            })
            ->where('li.paid_date','>=',$monthStart)
            ->where('li.paid_date','<',$monthEndEx)
            ->whereIn('s.ao_code', $aoCodes)
            ->groupBy('s.ao_code')
            ->selectRaw('s.ao_code as ao_code, SUM(COALESCE(li.penalty_paid,0)) as denda_masuk')
            ->pluck('denda_masuk','ao_code');

        // ===== helpers scoring =====
        $scorePercent = function (?float $ratio): int {
            if ($ratio === null) return 1;
            if ($ratio < 0.25) return 1;
            if ($ratio < 0.50) return 2;
            if ($ratio < 0.75) return 3;
            if ($ratio < 1.00) return 4;
            if ($ratio <= 1.0000001) return 5;
            return 6;
        };

        // NOTE: kalau NOA mau berbasis target, nanti kita ganti.
        $scoreNoa = function (int $n): int {
            if ($n <= 0) return 1;
            if ($n === 1) return 2;
            if ($n <= 3) return 3;
            if ($n <= 5) return 4;
            if ($n === 6) return 5;
            return 6;
        };

        $rows = [];

        foreach ($users as $u) {

            // ✅ FIX PENTING: tahan alias "id" vs "be_user_id"
            $id = (int)($u->id ?? $u->be_user_id ?? $u->user_id ?? 0);
            if ($id <= 0) {
                // kalau mau debug:
               
                continue;
            }

            $ao = (string)($u->ao_code ?? '');
            if (trim($ao) === '') continue;

            $t = $targetsByUserId->get($id);

            $tOs    = (float)($t->target_os_selesai ?? 0);
            $tNoa   = (int)  ($t->target_noa_selesai ?? 0);
            $tBunga = (float)($t->target_bunga_masuk ?? 0);
            $tDenda = (float)($t->target_denda_masuk ?? 0);

            $aOs    = (float)($recoveryByAo[$ao] ?? 0);
            $aNoa   = (int)  ($noaDoneByAo[$ao] ?? 0);
            $aBunga = (float)($interestByAo[$ao] ?? 0);
            $aDenda = (float)($penaltyByAo[$ao] ?? 0);

            $osPrev  = (float)($nplPrevByAo[$ao] ?? 0);
            $osNow   = (float)($nplNowByAo[$ao] ?? 0);
            $netDrop = $osPrev - $osNow;

            $rOs    = $tOs > 0 ? ($aOs / $tOs) : null;
            $rBunga = $tBunga > 0 ? ($aBunga / $tBunga) : null;
            $rDenda = $tDenda > 0 ? ($aDenda / $tDenda) : null;

            $sOs    = $scorePercent($rOs);
            $sNoa   = $scoreNoa($aNoa);
            $sBunga = $scorePercent($rBunga);
            $sDenda = $scorePercent($rDenda);

            $piOs    = $sOs * $this->wOs;
            $piNoa   = $sNoa * $this->wNoa;
            $piBunga = $sBunga * $this->wBunga;
            $piDenda = $sDenda * $this->wDenda;

            $totalPi = $piOs + $piNoa + $piBunga + $piDenda;

            $rows[$id] = [
                'be_user_id' => $id,
                'name' => $u->name ?? '-',
                'code' => $ao,

                'source' => 'realtime',
                'status' => null,

                'target' => [
                    'os' => $tOs, 'noa' => $tNoa, 'bunga' => $tBunga, 'denda' => $tDenda,
                ],
                'actual' => [
                    'os' => $aOs,
                    'noa' => $aNoa,
                    'bunga' => $aBunga,
                    'denda' => $aDenda,
                    'os_npl_prev' => $osPrev,
                    'os_npl_now' => $osNow,
                    'net_npl_drop' => $netDrop,
                ],
                'score' => [
                    'os' => $sOs, 'noa' => $sNoa, 'bunga' => $sBunga, 'denda' => $sDenda,
                ],
                'pi' => [
                    'os' => round($piOs, 2),
                    'noa' => round($piNoa, 2),
                    'bunga' => round($piBunga, 2),
                    'denda' => round($piDenda, 2),
                    'total' => round($totalPi, 2),
                ],
            ];
        }

        return $rows;
    }

    public function calculateOneForSubmit(string $periodYm, int $beUserId): array
    {
        $users = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->where('id', $beUserId)
            ->whereNotNull('ao_code')->where('ao_code','!=','')
            ->get();

        if ($users->isEmpty()) {
            throw new \RuntimeException("User BE {$beUserId} tidak punya ao_code, tidak bisa hitung KPI BE.");
        }

        $periodStart = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        $periodDate  = $periodStart->toDateString();

        $targets = KpiBeTarget::query()
            ->where('period', $periodDate)
            ->where('be_user_id', $beUserId)
            ->get()
            ->keyBy('be_user_id');

        $rows = $this->calculateRealtime($periodYm, $users, $targets);

        if (!isset($rows[$beUserId])) {
            $keys = implode(',', array_keys($rows));
            throw new \RuntimeException("KPI BE row tidak terbentuk untuk user {$beUserId}. Available keys: {$keys}");
        }

        return $rows[$beUserId] ?? throw new \RuntimeException("KPI BE row tidak terbentuk untuk user {$beUserId}.");
    }

    // tambahkan di BeKpiMonthlyService
    public function calculateRealtimePublic(string $periodYm, \Illuminate\Support\Collection $users, \Illuminate\Support\Collection $targetsByUserId): array
    {
        return $this->calculateRealtime($periodYm, $users, $targetsByUserId);
    }
}