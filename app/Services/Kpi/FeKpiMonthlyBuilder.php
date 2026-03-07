<?php

namespace App\Services\Kpi;

use App\Models\KpiFeMonthly;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FeKpiMonthlyBuilder
{

    public function build(string $periodYmd, ?string $mode = null, ?int $actorId = null)
    {

        $period = Carbon::parse($periodYmd)->startOfMonth();
        $periodDate = $period->toDateString();

        $monthStart = $period->copy()->startOfMonth()->toDateString();
        $monthEnd   = $period->copy()->endOfMonth()->toDateString();

        $mode = $mode ?? ($period->gte(now()->startOfMonth()) ? 'realtime' : 'eom');

        // =========================
        // snapshot awal (bulan sebelumnya)
        // =========================

        $prevMonth = $period->copy()->subMonth()->startOfMonth()->toDateString();

        $prev = DB::table('loan_account_snapshots_monthly')
            ->whereDate('snapshot_month', $prevMonth)
            ->where('kolek',2)
            ->selectRaw("
                ao_code,
                SUM(outstanding) as os_kol2_awal
            ")
            ->groupBy('ao_code')
            ->get()
            ->keyBy('ao_code');


        // =========================
        // posisi akhir
        // =========================

        if($mode == 'eom'){

            $cur = DB::table('loan_account_snapshots_monthly')
                ->whereDate('snapshot_month',$periodDate)
                ->selectRaw("
                    ao_code,
                    SUM(CASE WHEN kolek = 2 THEN outstanding ELSE 0 END) as os_kol2_akhir,
                    SUM(CASE WHEN kolek >=3 THEN outstanding ELSE 0 END) as migrasi_npl_os
                ")
                ->groupBy('ao_code')
                ->get()
                ->keyBy('ao_code');

        }else{

            $latest = DB::table('loan_accounts')->max('position_date');

            $cur = DB::table('loan_accounts')
                ->whereDate('position_date',$latest)
                ->selectRaw("
                    ao_code,
                    SUM(CASE WHEN kolek = 2 THEN outstanding ELSE 0 END) as os_kol2_akhir,
                    SUM(CASE WHEN kolek >=3 THEN outstanding ELSE 0 END) as migrasi_npl_os
                ")
                ->groupBy('ao_code')
                ->get()
                ->keyBy('ao_code');

        }


        // =========================
        // penalty masuk
        // =========================

        $penalty = DB::table('loan_installments')
            ->whereBetween('paid_date',[$monthStart,$monthEnd])
            ->selectRaw("
                ao_code,
                SUM(penalty_paid) as penalty_paid_total
            ")
            ->groupBy('ao_code')
            ->get()
            ->keyBy('ao_code');


        // =========================
        // FE users
        // =========================

        $feUsers = DB::table('users')
            ->whereRaw("UPPER(TRIM(level))='FE'")
            ->whereNotNull('ao_code')
            ->select('id','ao_code')
            ->get();


        foreach($feUsers as $u){

            $ao = $u->ao_code;

            $osAwal = (float)($prev[$ao]->os_kol2_awal ?? 0);
            $osAkhir = (float)($cur[$ao]->os_kol2_akhir ?? 0);

            $migrasi = (float)($cur[$ao]->migrasi_npl_os ?? 0);

            $penaltyPaid = (float)($penalty[$ao]->penalty_paid_total ?? 0);

            // =========================
            // perhitungan KPI
            // =========================

            $osTurun = max($osAwal - $osAkhir,0);

            $osTurunPct = $osAwal > 0
                ? round(($osTurun / $osAwal)*100,4)
                : 0;

            $migrasiPct = $osAwal > 0
                ? round(($migrasi / $osAwal)*100,4)
                : 0;

            // =========================
            // target
            // =========================

            $target = DB::table('kpi_fe_targets')
                ->whereDate('period',$periodDate)
                ->where('fe_user_id',$u->id)
                ->first();

            $targetOs = $target->target_os_turun_kol2 ?? 0;
            $targetPenalty = $target->target_penalty_paid ?? 0;
            $targetMigrasi = $target->target_migrasi_npl_pct ?? 0.3;


            $achOs = $targetOs > 0 ? ($osTurun/$targetOs)*100 : 0;
            $achPenalty = $targetPenalty > 0 ? ($penaltyPaid/$targetPenalty)*100 : 0;

            // =========================
            // score
            // =========================

            $scoreOs = $this->scoreFromAchievement($achOs);
            $scorePenalty = $this->scoreFromAchievement($achPenalty);
            $scoreMigrasi = $this->scoreMigrasiFromPct($migrasiPct);


            $piOs = $scoreOs * 0.4;
            $piPenalty = $scorePenalty * 0.2;
            $piMigrasi = $scoreMigrasi * 0.4;

            $total = $piOs + $piPenalty + $piMigrasi;


            // =========================
            // save
            // =========================

            KpiFeMonthly::updateOrCreate(

                [
                    'period'=>$periodDate,
                    'calc_mode'=>$mode,
                    'fe_user_id'=>$u->id
                ],

                [

                    'ao_code'=>$ao,

                    'os_kol2_awal'=>$osAwal,
                    'os_kol2_akhir'=>$osAkhir,

                    'os_kol2_turun'=>$osTurun,
                    'os_kol2_turun_total'=>$osTurun,
                    'os_kol2_turun_murni'=>$osTurun,
                    'os_kol2_turun_migrasi'=>$migrasi,
                    'os_kol2_turun_pct'=>$osTurunPct,

                    'migrasi_npl_os'=>$migrasi,
                    'migrasi_npl_pct'=>$migrasiPct,

                    'penalty_paid_total'=>$penaltyPaid,

                    'target_os_turun_kol2'=>$targetOs,
                    'target_migrasi_npl_pct'=>$targetMigrasi,
                    'target_penalty_paid'=>$targetPenalty,

                    'ach_os_turun_pct'=>$achOs,
                    'ach_migrasi_pct'=>0,
                    'ach_penalty_pct'=>$achPenalty,

                    'score_os_turun'=>$scoreOs,
                    'score_migrasi'=>$scoreMigrasi,
                    'score_penalty'=>$scorePenalty,

                    'pi_os_turun'=>$piOs,
                    'pi_migrasi'=>$piMigrasi,
                    'pi_penalty'=>$piPenalty,

                    'total_score_weighted'=>$total,

                    'calculated_by'=>$actorId,
                    'calculated_at'=>now()

                ]
            );

        }

    }


    private function scoreFromAchievement(float $ach): float
    {
        if ($ach > 100) return 6;
        if ($ach >= 100) return 5;
        if ($ach >= 75) return 4;
        if ($ach >= 50) return 3;
        if ($ach >= 25) return 2;
        return 1;
    }

    private function scoreMigrasiFromPct(float $pct): float
    {

        if ($pct <= 0) return 6;
        if ($pct <= 0.30) return 5;
        if ($pct <= 0.49) return 4;
        if ($pct <= 0.99) return 3;
        if ($pct <= 1.49) return 2;
        return 1;

    }

}