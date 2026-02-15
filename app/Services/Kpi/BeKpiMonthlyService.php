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

    /**
     * FE pattern:
     * buildForPeriod(periodYm, authUser) -> ['period','weights','items','mode'(optional)]
     */
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

        // scope: sementara simplest (sesuai keputusanmu: KBL yang bisa recalc/lihat semua)
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
                'period'  => $period,
                'mode'    => 'monthly', // optional
                'weights' => $this->weights(),
                'items'   => collect(),
            ];
        }

        $beIds = $users->pluck('id')->map(fn($x)=>(int)$x)->values()->all();

        // monthlies (frozen)
        $monthlies = KpiBeMonthly::query()
            ->where('period', $periodDate)
            ->whereIn('be_user_id', $beIds)
            ->get()
            ->keyBy('be_user_id');

        // targets
        $targets = KpiBeTarget::query()
            ->where('period', $periodDate)
            ->whereIn('be_user_id', $beIds)
            ->get()
            ->keyBy('be_user_id');

        // realtime untuk yg belum ada monthly
        $needCalcUsers = $users->filter(fn($u) => !$monthlies->has((int)$u->id))->values();

        $calcRows = [];
        if ($needCalcUsers->isNotEmpty()) {
            $calcRows = $this->calculateRealtime($periodYm, $needCalcUsers, $targets);
        }

        // build items collection (format cocok untuk sheet_be partial)
        $items = $users->map(function ($u) use ($monthlies, $targets, $calcRows) {
            $id = (int)$u->id;

            // jika ada monthly => pakai itu (frozen)
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

            // kalau belum ada monthly => realtime row
            return $calcRows[$id] ?? [
                'be_user_id' => $id,
                'name' => $u->name,
                'code' => (string)$u->ao_code,
                'source' => 'realtime',
                'status' => null,
                'target' => ['os'=>0,'noa'=>0,'bunga'=>0,'denda'=>0],
                'actual' => ['os'=>0,'noa'=>0,'bunga'=>0,'denda'=>0,'os_npl_prev'=>0,'os_npl_now'=>0,'net_npl_drop'=>0],
                'score'  => ['os'=>1,'noa'=>1,'bunga'=>1,'denda'=>1],
                'pi'     => ['os'=>round(1*$this->wOs,2),'noa'=>round(1*$this->wNoa,2),'bunga'=>round(1*$this->wBunga,2),'denda'=>round(1*$this->wDenda,2),'total'=>round(1.0,2)],
            ];
        });

        // urutkan berdasar total PI desc
        $items = $items->sortByDesc(fn($x) => (float)($x['pi']['total'] ?? 0))->values();

        return [
            'period'  => $period,
            'mode'    => 'mixed', // optional
            'weights' => $this->weights(),
            'items'   => $items,
            // 'tlBeRecap' => null (nanti kalau mau)
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

    /**
     * Scope sementara (sesuai keputusan: KBL boleh recalc/lihat semua; BE lihat diri sendiri).
     * Kalau nanti mau pakai org_assignments, tinggal ganti fungsi ini.
     */
    private function resolveScopeUserIds($authUser, string $periodDate): array
    {
        if (!$authUser) return [];

        // pakai helper hasAnyRole bila ada
        $isKbl = method_exists($authUser, 'hasAnyRole') && $authUser->hasAnyRole(['KBL']);

        // fallback: level enum/string aman
        $rawLvl = $authUser->level ?? '';
        $lvl = strtoupper(trim((string)($rawLvl instanceof \BackedEnum ? $rawLvl->value : $rawLvl)));

        if ($isKbl || $lvl === 'KBL') {
            return DB::table('users')
                ->whereRaw("UPPER(TRIM(level)) = 'BE'")
                ->pluck('id')
                ->map(fn($x)=>(int)$x)
                ->values()
                ->all();
        }

        // BE hanya dirinya
        return [(int)$authUser->id];
    }

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

        // ===== Recovery OS (prev kolek 3/4/5 -> (lunas / hilang) OR menjadi kolek 1/2) =====
        $recoveryByAo = DB::table('loan_account_snapshots_monthly as prev')
            ->leftJoin('loan_account_snapshots_monthly as cur', function($j) use ($currentSnap) {
                $j->on('cur.account_no','=','prev.account_no')
                  ->where('cur.snapshot_month', $currentSnap);
            })
            ->where('prev.snapshot_month', $prevSnap)
            ->whereIn('prev.ao_code', $aoCodes)
            ->whereIn('prev.kolek', [3,4,5])
            ->where(function($q) {
                $q->whereNull('cur.account_no')
                  ->orWhereIn('cur.kolek', [1,2]);
            })
            ->groupBy('prev.ao_code')
            ->selectRaw('prev.ao_code as ao_code, SUM(prev.outstanding) as recovery_os')
            ->pluck('recovery_os','ao_code');

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

        // ===== NOA selesai =====
        $noaDoneByAo = DB::table('loan_account_snapshots_monthly as prev')
            ->leftJoin('loan_account_snapshots_monthly as cur', function($j) use ($currentSnap) {
                $j->on('cur.account_no','=','prev.account_no')
                  ->where('cur.snapshot_month', $currentSnap);
            })
            ->where('prev.snapshot_month', $prevSnap)
            ->whereIn('prev.ao_code', $aoCodes)
            ->whereIn('prev.kolek', [3,4,5])
            ->where(function($q) {
                $q->whereNull('cur.account_no')
                  ->orWhereIn('cur.kolek', [1,2]);
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
            $id = (int)$u->id;
            $ao = (string)$u->ao_code;

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
                'name' => $u->name,
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

        /**
     * COMPAT: dipakai oleh Command lama BuildBeKpiMonthly
     * Kalau command memanggil calculateOneForSubmit(), aman.
     */
    public function calculateOneForSubmit(string $periodYm, int $beUserId): array
    {
        // kalau kamu sudah punya implementasi realtime:
        // panggil calculateRealtime untuk 1 user lalu return rownya

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

        return $rows[$beUserId] ?? throw new \RuntimeException("KPI BE row tidak terbentuk untuk user {$beUserId}.");
    }

}
