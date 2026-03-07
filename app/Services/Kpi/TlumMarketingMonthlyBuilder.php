<?php

namespace App\Services\Kpi;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class TlumMarketingMonthlyBuilder
{
    /**
     * Build & upsert TLUM monthly untuk 1 leader (user TLUM) pada periode YYYY-MM-01
     */
    public function build(int $tlumUserId, string $periodDate): ?object
    {
        $period = Carbon::parse($periodDate)->startOfMonth();
        $periodDate = $period->toDateString();

        $me = User::find($tlumUserId);
        if (!$me) return null;

        try {
            // ===== aliases TLUM (samakan dengan controller kamu) =====
            $roleAliases = ['tl', 'tlum', 'tl um', 'tl-um', 'tl_um', 'tl umkm', 'tl-umkm', 'tl_umkm'];

            // ===== scope AO under TLUM =====
            $subUserIds = DB::table('org_assignments')
                ->where('leader_id', (int)$me->id)
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

            if (empty($aoUserIds)) {
                // upsert kosong biar ranking/summary aman
                return $this->storeEmpty($me->id, $periodDate);
            }

            // ===== weights TLUM (samakan dengan controller kamu) =====
            $wT = ['noa'=>0.30,'os'=>0.20,'rr'=>0.25,'com'=>0.20];

            // ===== YTD range (Jan..period) =====
            $startYtd = $period->copy()->startOfYear()->toDateString();
            $endYtd   = $period->copy()->endOfMonth()->toDateString();

            // ===== sub actual YTD (kpi_ao_monthlies, scheme AO_UMKM) =====
            $subActualYtd = DB::table('kpi_ao_monthlies')
                ->where('scheme', 'AO_UMKM')
                ->whereBetween('period', [$startYtd, $endYtd])
                ->groupBy('user_id')
                ->selectRaw("
                    user_id,
                    SUM(os_disbursement)        as os_disbursement,
                    SUM(noa_disbursement)       as noa_disbursement,
                    SUM(community_actual)       as community_actual,
                    SUM(rr_os_total)            as rr_os_total,
                    SUM(rr_os_current)          as rr_os_current
                ");

            // ===== sub target YTD =====
            $subTargetYtd = DB::table('kpi_ao_targets')
                ->whereBetween('period', [$startYtd, $endYtd])
                ->groupBy('user_id')
                ->selectRaw("
                    user_id,
                    SUM(target_os_disbursement)     as target_os_disbursement,
                    SUM(target_noa_disbursement)    as target_noa_disbursement,
                    MAX(target_rr)                  as target_rr,
                    SUM(target_community)           as target_community
                ");

            $rows = DB::query()
                ->fromSub($subActualYtd, 'm')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->leftJoinSub($subTargetYtd, 't', function ($j) {
                    $j->on('t.user_id', '=', 'm.user_id');
                })
                ->whereIn('u.id', $aoUserIds)
                ->select([
                    'u.id as user_id','u.name','u.ao_code',

                    't.target_os_disbursement',
                    't.target_noa_disbursement',
                    't.target_rr',
                    't.target_community',

                    'm.os_disbursement',
                    'm.noa_disbursement',
                    'm.rr_os_total',
                    'm.rr_os_current',
                    'm.community_actual',
                ])
                ->get();

            if ($rows->isEmpty()) {
                return $this->storeEmpty($me->id, $periodDate);
            }

            // ===== agregasi TLUM =====
            $sumTargetNoa = (int) $rows->sum(fn($x) => (int)($x->target_noa_disbursement ?? 0));
            $sumTargetOs  = (int) $rows->sum(fn($x) => (int)($x->target_os_disbursement ?? 0));
            $avgTargetRr  = (float)($rows->avg(fn($x) => (float)($x->target_rr ?? 100)) ?? 100.0);
            $sumTargetCom = (int) $rows->sum(fn($x) => (int)($x->target_community ?? 0));

            $sumActualNoa = (int) $rows->sum(fn($x) => (int)($x->noa_disbursement ?? 0));
            $sumActualOs  = (int) $rows->sum(fn($x) => (int)($x->os_disbursement ?? 0));
            $sumActualCom = (int) $rows->sum(fn($x) => (int)($x->community_actual ?? 0));

            $sumRrTotal   = (int) $rows->sum(fn($x) => (int)($x->rr_os_total ?? 0));
            $sumRrCurrent = (int) $rows->sum(fn($x) => (int)($x->rr_os_current ?? 0));
            $rrWeighted   = $sumRrTotal > 0 ? round(100.0 * $sumRrCurrent / $sumRrTotal, 2) : 0.0;

            $noaPct = KpiScoreHelper::safePct((float)$sumActualNoa, (float)$sumTargetNoa);
            $osPct  = KpiScoreHelper::safePct((float)$sumActualOs,  (float)$sumTargetOs);
            $comPct = KpiScoreHelper::safePct((float)$sumActualCom, (float)$sumTargetCom);

            $scoreNoa = KpiScoreHelper::scoreBand1to6((float)$noaPct);
            $scoreOs  = KpiScoreHelper::scoreBand1to6((float)$osPct);
            $scoreRr  = KpiScoreHelper::scoreFromRepaymentRateAo6($rrWeighted);
            $scoreCom = KpiScoreHelper::scoreBand1to6((float)$comPct);

            $piNoa = round($scoreNoa * $wT['noa'], 2);
            $piOs  = round($scoreOs  * $wT['os'],  2);
            $piRr  = round($scoreRr  * $wT['rr'],  2);
            $piCom = round($scoreCom * $wT['com'], 2);
            $piTot = round($piNoa + $piOs + $piRr + $piCom, 2);

            DB::table('kpi_tlum_monthlies')->updateOrInsert(
                ['period' => $periodDate, 'tlum_user_id' => (int)$me->id],
                [
                    'noa_target' => $sumTargetNoa,
                    'os_target'  => $sumTargetOs,
                    'rr_target'  => round($avgTargetRr, 2),
                    'com_target' => $sumTargetCom,

                    'noa_actual' => $sumActualNoa,
                    'os_actual'  => $sumActualOs,
                    'rr_actual'  => (float)$rrWeighted,
                    'com_actual' => $sumActualCom,

                    'noa_pct'    => (float)round($noaPct, 2),
                    'os_pct'     => (float)round($osPct, 2),
                    'com_pct'    => (float)round($comPct, 2),

                    'score_noa'  => (float)$scoreNoa,
                    'score_os'   => (float)$scoreOs,
                    'score_rr'   => (float)$scoreRr,
                    'score_com'  => (float)$scoreCom,

                    'pi_total'   => (float)$piTot,
                    'calculated_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            return DB::table('kpi_tlum_monthlies')
                ->where('period', $periodDate)
                ->where('tlum_user_id', (int)$me->id)
                ->first();

        } catch (\Throwable $e) {
            Log::warning('TLUM builder failed: '.$e->getMessage(), [
                'period' => $periodDate,
                'tlum_user_id' => (int)$tlumUserId,
            ]);

            return null;
        }
    }

    private function storeEmpty(int $tlumUserId, string $periodDate): ?object
    {
        DB::table('kpi_tlum_monthlies')->updateOrInsert(
            ['period' => $periodDate, 'tlum_user_id' => $tlumUserId],
            [
                'noa_target'=>0,'os_target'=>0,'rr_target'=>0,'com_target'=>0,
                'noa_actual'=>0,'os_actual'=>0,'rr_actual'=>0,'com_actual'=>0,
                'noa_pct'=>0,'os_pct'=>0,'com_pct'=>0,
                'score_noa'=>0,'score_os'=>0,'score_rr'=>0,'score_com'=>0,
                'pi_total'=>0,
                'calculated_at'=>now(),
                'updated_at'=>now(),
                'created_at'=>now(),
            ]
        );

        return DB::table('kpi_tlum_monthlies')
            ->where('period', $periodDate)
            ->where('tlum_user_id', $tlumUserId)
            ->first();
    }
}