<?php

namespace App\Http\Controllers\Kpi;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketingKpiSheetController
{
public function index(Request $request)
{
    $periodYm = $request->query('period', now()->format('Y-m'));
    $period   = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();

    // ✅ penting: string date untuk query
    $periodDate = $period->toDateString();

    // ✅ role dari query (GET)
    $role = strtoupper((string)($request->query('role', 'AO')));
    if (!in_array($role, ['AO', 'SO'], true)) $role = 'AO';

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
            ->where('m.period', $periodDate)   // ✅ FIX
            ->whereIn('u.level', ['SO'])
            ->select([
                'u.id as user_id','u.name','u.ao_code','u.level',
                't.id as target_id',
                't.target_os_disbursement',
                't.target_noa_disbursement',
                't.target_rr',                // ✅ ambil beneran dari target
                't.target_activity',
                'm.os_disbursement',
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

            // ✅ target RR ambil dari target, fallback 100
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
            'period'   => $period,      // sudah Carbon
            'weights'  => $weights,
            'items'    => $items,
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
        ->where('m.period', $periodDate)   // ✅ FIX
        ->whereIn('u.level', ['AO'])
        // ->whereIn('u.level', ['AO','RO','SO','FE','BE'])
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
                'ach_rr'       => (float)($r->rr_pct ?? 0),          // sudah percent
                'ach_kolek'    => (float)($r->npl_migration_pct ?? 0), // already percent (lebih kecil lebih baik)
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
            'role'    => $role,
            'periodYm'=> $periodYm,
            'period'  => Carbon::parse($period),
            'weights' => $weights,
            'items'   => $items,
        ]);
    }

    private function pct($actual, $target): float
    {
        $a = (float)($actual ?? 0);
        $t = (float)($target ?? 0);
        if ($t == 0.0) return 0.0;
        return round(($a / $t) * 100.0, 2);
    }
}
