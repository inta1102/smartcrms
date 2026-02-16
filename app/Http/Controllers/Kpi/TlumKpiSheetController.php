<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TlumKpiSheetController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        abort_if(!$u, 401);

        $periodDate = Carbon::parse($request->get('period', now()->startOfMonth()->toDateString()))
            ->startOfMonth()
            ->toDateString();

        // =============================
        // 1) Ambil AO scope TLUM (org_assignments)
        // NOTE:
        // - tabel kamu tidak punya user_role, jadi jangan difilter.
        // - leader_role di data kamu "TL", jadi alias harus include TL.
        // =============================
        $leaderRoleAliases = ['tlum', 'tl um', 'tl-um', 'tl_um', 'tl'];

        $aoUserIds = DB::table('org_assignments')
            ->where('leader_id', (int) $u->id)
            ->where('is_active', 1)
            ->whereIn(DB::raw('LOWER(TRIM(leader_role))'), $leaderRoleAliases)
            ->whereDate('effective_from', '<=', $periodDate)
            ->where(function ($q) use ($periodDate) {
                $q->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $periodDate);
            })
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();

        // Kalau kosong, tampilkan view dengan data kosong
        if (empty($aoUserIds)) {
            return view('kpi.tlum.sheet', [
                'periodDate'   => $periodDate,
                'tlumSummary'  => null,
                'items'        => collect(),
                'weights'      => $this->weightsUmkm(),
            ]);
        }

        // =============================
        // 2) Ranking AO UMKM dalam scope TLUM
        // =============================
        $rows = DB::table('kpi_ao_monthlies as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('kpi_ao_targets as t', function ($j) use ($periodDate) {
                $j->on('t.user_id', '=', 'm.user_id')
                    ->where('t.period', '=', $periodDate);
            })
            ->where('m.period', $periodDate)
            ->whereIn('u.id', $aoUserIds)
            ->where('m.scheme', 'AO_UMKM')
            ->select([
                'u.id as user_id', 'u.name', 'u.ao_code',

                // totals
                'm.score_total',

                // target UMKM
                't.target_os_disbursement',
                't.target_noa_disbursement',
                't.target_rr',
                't.target_community',
                't.target_daily_report',

                // actual UMKM
                'm.os_disbursement',
                'm.noa_disbursement',
                'm.os_disbursement_pct',
                'm.noa_disbursement_pct',
                'm.rr_pct',
                'm.community_actual',
                'm.community_pct',
                'm.daily_report_actual',
                'm.daily_report_pct',

                // scores UMKM
                'm.score_os',
                'm.score_noa',
                'm.score_rr',
                'm.score_community',
                'm.score_daily_report',
            ])
            ->orderByDesc('m.score_total')
            ->orderBy('u.name')
            ->get();

        $w = $this->weightsUmkm();

        $items = $rows->map(function ($r) use ($w) {
            $piNoa = round(((float)($r->score_noa ?? 0))          * $w['noa'], 2);
            $piOs  = round(((float)($r->score_os ?? 0))           * $w['os'], 2);
            $piRr  = round(((float)($r->score_rr ?? 0))           * $w['rr'], 2);
            $piCom = round(((float)($r->score_community ?? 0))    * $w['community'], 2);
            $piDay = round(((float)($r->score_daily_report ?? 0)) * $w['daily'], 2);
            $piTot = round($piNoa + $piOs + $piRr + $piCom + $piDay, 2);

            return (object) array_merge((array) $r, [
                'pi_noa'       => $piNoa,
                'pi_os'        => $piOs,
                'pi_rr'        => $piRr,
                'pi_community' => $piCom,
                'pi_daily'     => $piDay,
                'pi_total'     => $piTot,
            ]);
        });

        // =============================
        // 3) TLUM Summary (agregasi scope AO)
        //    - Target: SUM target tiap AO
        //    - Actual: SUM actual
        //    - RR TLUM: rata-rata tertimbang by OS (pakai os_disbursement sebagai bobot paling masuk akal)
        // =============================
        $sumTargetNoa = (int) $rows->sum(fn($x) => (int)($x->target_noa_disbursement ?? 0));
        $sumNoa       = (int) $rows->sum(fn($x) => (int)($x->noa_disbursement ?? 0));

        $sumTargetOs  = (int) $rows->sum(fn($x) => (int)($x->target_os_disbursement ?? 0));
        $sumOs        = (int) $rows->sum(fn($x) => (int)($x->os_disbursement ?? 0));

        $sumTargetCom = (int) $rows->sum(fn($x) => (int)($x->target_community ?? 0));
        $sumCom       = (int) $rows->sum(fn($x) => (int)($x->community_actual ?? 0));

        $sumTargetDay = (int) $rows->sum(fn($x) => (int)($x->target_daily_report ?? 0));
        $sumDay       = (int) $rows->sum(fn($x) => (int)($x->daily_report_actual ?? 0));

        // RR tertimbang: weight = os_disbursement (kalau 0 semua, fallback simple avg)
        $rrWeightedDen = (float) $rows->sum(fn($x) => (float)($x->os_disbursement ?? 0));
        if ($rrWeightedDen > 0) {
            $rrWeightedNum = (float) $rows->sum(fn($x) => ((float)($x->rr_pct ?? 0)) * (float)($x->os_disbursement ?? 0));
            $tlumRrPct = round($rrWeightedNum / $rrWeightedDen, 2);
        } else {
            $tlumRrPct = round((float)($rows->avg(fn($x) => (float)($x->rr_pct ?? 0)) ?? 0), 2);
        }

        $tlumNoaPct = $this->safePct($sumNoa, $sumTargetNoa);
        $tlumOsPct  = $this->safePct($sumOs, $sumTargetOs);
        $tlumComPct = $this->safePct($sumCom, $sumTargetCom);
        $tlumDayPct = $this->safePct($sumDay, $sumTargetDay);

        // Skor TLUM pakai rubrik yang sama (1..6) -> PI TLUM
        // (kamu bisa adjust rubrik TLUM beda, tapi ini paling konsisten dulu)
        $scoreNoa = \App\Services\Kpi\KpiScoreHelper::scoreFromAoNoaGrowth6($sumNoa); // pakai count total scope
        $scoreOs  = \App\Services\Kpi\KpiScoreHelper::scoreFromAoOsRealisasiPct6($tlumOsPct);
        $scoreRr  = \App\Services\Kpi\KpiScoreHelper::scoreFromRepaymentRateAo6($tlumRrPct);
        $scoreCom = \App\Services\Kpi\KpiScoreHelper::scoreFromAoCommunity6($sumCom); // count total scope
        $scoreDay = \App\Services\Kpi\KpiScoreHelper::scoreFromAoDailyReport6($sumDay); // count total scope

        $piNoa = round($scoreNoa * $w['noa'], 2);
        $piOs  = round($scoreOs  * $w['os'], 2);
        $piRr  = round($scoreRr  * $w['rr'], 2);
        $piCom = round($scoreCom * $w['community'], 2);
        $piDay = round($scoreDay * $w['daily'], 2);
        $piTot = round($piNoa + $piOs + $piRr + $piCom + $piDay, 2);

        $tlumSummary = (object) [
            'target_noa_disbursement' => $sumTargetNoa,
            'noa_disbursement'        => $sumNoa,
            'noa_pct'                 => round($tlumNoaPct, 2),

            'target_os_disbursement'  => $sumTargetOs,
            'os_disbursement'         => $sumOs,
            'os_pct'                  => round($tlumOsPct, 2),

            'target_rr'               => null,
            'rr_pct'                  => $tlumRrPct,

            'target_community'        => $sumTargetCom,
            'community_actual'        => $sumCom,
            'community_pct'           => round($tlumComPct, 2),

            'target_daily_report'     => $sumTargetDay,
            'daily_report_actual'     => $sumDay,
            'daily_report_pct'        => round($tlumDayPct, 2),

            'score_noa'               => $scoreNoa,
            'score_os'                => $scoreOs,
            'score_rr'                => $scoreRr,
            'score_community'         => $scoreCom,
            'score_daily_report'      => $scoreDay,

            'pi_noa'                  => $piNoa,
            'pi_os'                   => $piOs,
            'pi_rr'                   => $piRr,
            'pi_community'            => $piCom,
            'pi_daily'                => $piDay,
            'pi_total'                => $piTot,
        ];

        return view('kpi.tlum.sheet', [
            'periodDate'  => $periodDate,
            'tlumSummary' => $tlumSummary,
            'items'       => $items,
            'weights'     => $w,
        ]);
    }

    private function weightsUmkm(): array
    {
        return [
            'noa'       => 0.30,
            'os'        => 0.20,
            'rr'        => 0.25,
            'community' => 0.20,
            'daily'     => 0.05,
        ];
    }

    private function safePct(float $num, float $den): float
    {
        if ($den <= 0) return 0.0;
        return ($num / $den) * 100.0;
    }
}
