<?php

namespace App\Services\Kpi;

use App\Models\KpiBeMonthly;
use App\Models\KpiBeTarget;
use App\Services\Org\OrgVisibilityService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class KsbeKpiMonthlyService
{
    public function __construct(
        private readonly BeKpiMonthlyService $beService,
        private readonly OrgVisibilityService $org,
    ) {}

    public function buildForPeriod(string $periodYm, $authUser): array
    {
        $leader = $authUser;
        logger()->info('KSBE buildForPeriod START', [
            'periodYm' => $periodYm,
            'me_id' => $leader?->id,
            'me_level' => $leader?->level,
        ]);

        // parse
        try {
            $period = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        } catch (\Throwable $e) {
            $period = now()->startOfMonth();
            $periodYm = $period->format('Y-m');
        }
        $periodDate = $period->toDateString(); // YYYY-MM-01

        $rawLvl = $authUser->level ?? '';
        $lvl = strtoupper(trim((string)($rawLvl instanceof \BackedEnum ? $rawLvl->value : $rawLvl)));

        // only KSBE/KASI/KBL can open
        abort_unless(in_array($lvl, ['KSBE','KASI','KBL'], true), 403);

        // scope bawahan BE
        $subIds = $lvl === 'KBL'
            ? DB::table('users')->whereRaw("UPPER(TRIM(level))='BE'")->pluck('id')->map(fn($x)=>(int)$x)->values()->all()
            : $this->org->subordinateUserIds((int)$authUser->id, $periodDate, 'remedial');

        // ambil user BE
        $users = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereIn('id', $subIds)
            ->whereRaw("UPPER(TRIM(level))='BE'")
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code)<>''")
            ->orderBy('name')
            ->get();

            logger()->info('KSBE SCOPE', [
                'leader_id'    => (int)($authUser?->id ?? 0),
                'scope_cnt'    => is_array($subIds) ? count($subIds) : 0,
                'scope_sample' => array_slice($subIds ?? [], 0, 10),
            ]);

        if ($users->isEmpty()) {
            return [
                'period' => $period,
                'mode'   => 'ksbe',
                'leader' => ['id'=>$authUser->id,'name'=>$authUser->name,'level'=>$lvl],
                'weights'=> $this->beServiceWeights(),
                'recap'  => $this->emptyRecap(),
                'items'  => collect(),
            ];
        }

        // pakai BE service kamu untuk build item bawahan (monthly/realtime mix)
        // trick: BE service expect role-based scoping; kita bypass dengan call internal calculateRealtime untuk list users
        // => cara paling aman: duplikasi ringan logic BE: ambil monthlies + targets + realtime untuk yang belum ada

        $beIds = $users->pluck('id')->map(fn($x)=>(int)$x)->values()->all();

        $monthlies = KpiBeMonthly::query()
            ->where('period', $periodDate)
            ->whereIn('be_user_id', $beIds)
            ->get()
            ->keyBy('be_user_id');

        logger()->info('KSBE KPI QUERY RESULT', [
            'period'        => $periodDate,
            'be_ids_cnt'    => count($beIds),
            'monthlies_cnt' => $monthlies->count(),
        ]);

        $targets = KpiBeTarget::query()
            ->where('period', $periodDate)
            ->whereIn('be_user_id', $beIds)
            ->get()
            ->keyBy('be_user_id');

        $needCalcUsers = $users->filter(fn($u) => !$monthlies->has((int)$u->id))->values();
        $calcRows = [];
        if ($needCalcUsers->isNotEmpty()) {
            // PENTING: calculateRealtime di BeKpiMonthlyService harus hidup (punyamu yang panjang itu)
            $calcRows = $this->callBeRealtime($periodYm, $needCalcUsers, $targets);
        }

        $items = $users->map(function ($u) use ($monthlies, $targets, $calcRows) {
            $id = (int)$u->id;

            if ($monthlies->has($id)) {
                $m = $monthlies->get($id);
                $t = $targets->get($id);

                return [
                    'be_user_id' => $id,
                    'name' => $u->name,
                    'code' => (string)$u->ao_code,
                    'source' => 'monthly',
                    'status' => (string)($m->status ?? 'draft'),
                    'target' => [
                        'os'    => (float)($t->target_os_selesai ?? 0),
                        'noa'   => (int)  ($t->target_noa_selesai ?? 0),
                        'bunga' => (float)($t->target_bunga_masuk ?? 0),
                        'denda' => (float)($t->target_denda_masuk ?? 0),
                    ],
                    'actual' => [
                        'os'    => (float)($m->actual_os_selesai ?? 0),
                        'noa'   => (int)  ($m->actual_noa_selesai ?? 0),
                        'bunga' => (float)($m->actual_bunga_masuk ?? 0),
                        'denda' => (float)($m->actual_denda_masuk ?? 0),
                        'os_npl_prev'  => (float)($m->os_npl_prev ?? 0),
                        'os_npl_now'   => (float)($m->os_npl_now ?? 0),
                        'net_npl_drop' => (float)($m->net_npl_drop ?? 0),
                    ],
                    'score' => [
                        'os'    => (int)($m->score_os ?? 1),
                        'noa'   => (int)($m->score_noa ?? 1),
                        'bunga' => (int)($m->score_bunga ?? 1),
                        'denda' => (int)($m->score_denda ?? 1),
                    ],
                    'pi' => [
                        'os'    => (float)($m->pi_os ?? 0),
                        'noa'   => (float)($m->pi_noa ?? 0),
                        'bunga' => (float)($m->pi_bunga ?? 0),
                        'denda' => (float)($m->pi_denda ?? 0),
                        'total' => (float)($m->total_pi ?? 0),
                    ],
                ];
            }

            return $calcRows[$id] ?? [
                'be_user_id'=>$id,'name'=>$u->name,'code'=>(string)$u->ao_code,
                'source'=>'realtime','status'=>null,
                'target'=>['os'=>0,'noa'=>0,'bunga'=>0,'denda'=>0],
                'actual'=>['os'=>0,'noa'=>0,'bunga'=>0,'denda'=>0,'os_npl_prev'=>0,'os_npl_now'=>0,'net_npl_drop'=>0],
                'score'=>['os'=>1,'noa'=>1,'bunga'=>1,'denda'=>1],
                'pi'=>['os'=>0,'noa'=>0,'bunga'=>0,'denda'=>0,'total'=>0],
            ];
        });

        // rekap agregat KSBE (dihitung dari SUM target/actual)
        $recap = $this->buildRecapFromItems($items);

        // ranking bawahan
        $items = $items->sortByDesc(fn($x)=>(float)($x['pi']['total'] ?? 0))->values();

        return [
            'period' => $period,
            'mode'   => 'ksbe',
            'leader' => ['id'=>$authUser->id,'name'=>$authUser->name,'level'=>$lvl],
            'weights'=> $this->beServiceWeights(),
            'recap'  => $recap,
            'items'  => $items,
        ];
    }

    private function beServiceWeights(): array
    {
        // match bobot BE service kamu
        return ['os'=>0.50,'noa'=>0.10,'bunga'=>0.20,'denda'=>0.20];
    }

    private function emptyRecap(): array
    {
        return [
            'target'=>['os'=>0,'noa'=>0,'bunga'=>0,'denda'=>0],
            'actual'=>['os'=>0,'noa'=>0,'bunga'=>0,'denda'=>0,'os_npl_prev'=>0,'os_npl_now'=>0,'net_npl_drop'=>0],
            'ach'   =>['os'=>0,'noa'=>0,'bunga'=>0,'denda'=>0],
            'score' =>['os'=>1,'noa'=>1,'bunga'=>1,'denda'=>1],
            'pi'    =>['os'=>0,'noa'=>0,'bunga'=>0,'denda'=>0,'total'=>0],
        ];
    }

    private function buildRecapFromItems(Collection $items): array
    {
        $w = $this->beServiceWeights();

        $tOs = (float)$items->sum(fn($x)=>(float)($x['target']['os'] ?? 0));
        $tNoa= (int)  $items->sum(fn($x)=>(int)  ($x['target']['noa'] ?? 0));
        $tB  = (float)$items->sum(fn($x)=>(float)($x['target']['bunga'] ?? 0));
        $tD  = (float)$items->sum(fn($x)=>(float)($x['target']['denda'] ?? 0));

        $aOs = (float)$items->sum(fn($x)=>(float)($x['actual']['os'] ?? 0));
        $aNoa= (int)  $items->sum(fn($x)=>(int)  ($x['actual']['noa'] ?? 0));
        $aB  = (float)$items->sum(fn($x)=>(float)($x['actual']['bunga'] ?? 0));
        $aD  = (float)$items->sum(fn($x)=>(float)($x['actual']['denda'] ?? 0));

        $osPrev = (float)$items->sum(fn($x)=>(float)($x['actual']['os_npl_prev'] ?? 0));
        $osNow  = (float)$items->sum(fn($x)=>(float)($x['actual']['os_npl_now'] ?? 0));
        $netDrop= $osPrev - $osNow;

        $achOs = $tOs>0 ? round(($aOs/$tOs)*100,2) : 0;
        $achNoa= $tNoa>0? round(($aNoa/$tNoa)*100,2): 0;
        $achB  = $tB>0  ? round(($aB/$tB)*100,2)  : 0;
        $achD  = $tD>0  ? round(($aD/$tD)*100,2)  : 0;

        // score band sama persis kayak BE (pakai pencapaian agregat)
        $scorePercent = function (?float $ratio): int {
            if ($ratio === null) return 1;
            if ($ratio < 0.25) return 1;
            if ($ratio < 0.50) return 2;
            if ($ratio < 0.75) return 3;
            if ($ratio < 1.00) return 4;
            if ($ratio <= 1.0000001) return 5;
            return 6;
        };

        // NOA: untuk leader, lebih adil pakai ratio target juga
        $sOs = $scorePercent($tOs>0 ? $aOs/$tOs : null);
        $sNoa= $scorePercent($tNoa>0? $aNoa/$tNoa : null);
        $sB  = $scorePercent($tB>0  ? $aB/$tB   : null);
        $sD  = $scorePercent($tD>0  ? $aD/$tD   : null);

        $piOs = round($sOs * $w['os'], 2);
        $piNoa= round($sNoa* $w['noa'],2);
        $piB  = round($sB  * $w['bunga'],2);
        $piD  = round($sD  * $w['denda'],2);
        $total= round($piOs+$piNoa+$piB+$piD, 2);

        return [
            'target'=>['os'=>$tOs,'noa'=>$tNoa,'bunga'=>$tB,'denda'=>$tD],
            'actual'=>['os'=>$aOs,'noa'=>$aNoa,'bunga'=>$aB,'denda'=>$aD,'os_npl_prev'=>$osPrev,'os_npl_now'=>$osNow,'net_npl_drop'=>$netDrop],
            'ach'   =>['os'=>$achOs,'noa'=>$achNoa,'bunga'=>$achB,'denda'=>$achD],
            'score' =>['os'=>$sOs,'noa'=>$sNoa,'bunga'=>$sB,'denda'=>$sD],
            'pi'    =>['os'=>$piOs,'noa'=>$piNoa,'bunga'=>$piB,'denda'=>$piD,'total'=>$total],
        ];
    }

    private function callBeRealtime(string $periodYm, Collection $users, $targetsByUserId): array
    {
        // akses method private gak bisa; jadi kamu lakukan salah satu:
        // (A) ubah calculateRealtime di BeKpiMonthlyService jadi protected/public
        // (B) pindahkan calculateRealtime logic ke Trait / helper service
        // Kita pilih A paling cepat:

        return app(BeKpiMonthlyService::class)->calculateRealtimePublic($periodYm, $users, $targetsByUserId);
    }
}