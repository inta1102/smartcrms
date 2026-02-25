<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Org\OrgScopeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class KslrLeadershipSheetController extends Controller
{
    public function index(Request $request, OrgScopeService $scope)
    {
        $me = $request->user();
        abort_unless($me, 403);

        Gate::authorize('kpi-kslr-view');

        // ==========================================================
        // 0) PERIOD
        // ==========================================================
        $periodYmd   = $this->resolvePeriodYmd($request);               // YYYY-MM-01
        $period      = Carbon::parse($periodYmd)->startOfMonth();
        $periodDate  = $period->toDateString();                        // YYYY-MM-01
        $periodYm    = $period->format('Y-m');                          // YYYY-MM
        $periodLabel = $period->translatedFormat('F Y');

        // ==========================================================
        // 1) SCOPE: Ambil descendant (TLRO/TLSO/SO/RO) dari KSLR
        // ==========================================================
        $descIds = $scope->descendantUserIds((int)$me->id, $periodYmd, 'lending', 3);

        // ==========================================================
        // 2) USERS (id, name, ao_code, role)
        // ==========================================================
        $users = User::query()
            ->whereIn('id', $descIds)
            ->get(['id','name','ao_code','level'])
            ->map(function ($u) {
                $role = $this->resolveRole($u);

                return (object)[
                    'id'      => (int)$u->id,
                    'name'    => (string)$u->name,
                    'ao_code' => $u->ao_code ? str_pad(trim((string)$u->ao_code), 6, '0', STR_PAD_LEFT) : null,
                    'role'    => $role,
                ];
            });

        $tlIds     = $users->filter(fn($u) => is_string($u->role) && str_starts_with($u->role, 'TL'))->pluck('id')->values()->all();
        $soIds     = $users->filter(fn($u) => $u->role === 'SO')->pluck('id')->values()->all();
        $roAoCodes = $users->filter(fn($u) => $u->role === 'RO' && !empty($u->ao_code))->pluck('ao_code')->unique()->values()->all();

        // ==========================================================
        // 3) KPI RO scope (RR & Migrasi DPK) - dari kpi_ro_monthly
        // ==========================================================
        $roRows = collect();
        if (!empty($roAoCodes)) {
            $roRows = DB::table('kpi_ro_monthly')
                ->select('ao_code','total_score_weighted','repayment_pct','dpk_pct')
                ->whereDate('period_month', $periodDate)
                ->whereIn('ao_code', $roAoCodes)
                ->get();
        }

        // ==========================================================
        // 4) KPI SO scope (Achievement KYD + Komunitas) - dari kpi_so_monthlies + targets
        // ==========================================================
        $soRows = collect();
        $soTargets = collect();

        if (!empty($soIds)) {
            $soRows = DB::table('kpi_so_monthlies')
                ->select('user_id','os_disbursement','noa_disbursement','rr_pct','activity_actual','score_total')
                ->whereDate('period', $periodDate)
                ->whereIn('user_id', $soIds)
                ->get();

            $soTargets = DB::table('kpi_so_targets')
                ->select('user_id','target_os_disbursement','target_noa_disbursement','target_rr','target_activity')
                ->whereDate('period', $periodDate)
                ->whereIn('user_id', $soIds)
                ->get()
                ->keyBy('user_id');
        }

        // ==========================================================
        // 5) AGREGASI METRIK KSLR
        // ==========================================================
        // (1) Achievement KYD (proxy: SUM OS disbursement SO / SUM target_os_disbursement)
        $sumOsAct  = (float) $soRows->sum('os_disbursement');
        $sumOsTgt  = (float) $soTargets->sum('target_os_disbursement');
        $kydAchPct = $sumOsTgt > 0 ? ($sumOsAct / $sumOsTgt) * 100 : 0;

        // (2) Migrasi DPK (proxy: AVG dpk_pct RO scope)
        $dpkMigPct = $roRows->count() ? (float) $roRows->avg('dpk_pct') : 0;

        // (3) Repayment Rate (proxy: AVG repayment_pct RO scope)
        $rrPct = $roRows->count() ? (float) $roRows->avg('repayment_pct') : 0;

        // (4) Handling Komunitas (proxy: SUM activity_actual SO / SUM target_activity)
        $sumActAct    = (float) $soRows->sum('activity_actual');
        $sumActTgt    = (float) $soTargets->sum('target_activity');
        $communityPct = $sumActTgt > 0 ? ($sumActAct / $sumActTgt) * 100 : 0;

        // scoring 1..6 sesuai guide
        $scoreKyd = $this->scoreKyd($kydAchPct);
        $scoreDpk = $this->scoreMigrasiDpk($dpkMigPct);
        $scoreRr  = $this->scoreRr($rrPct);
        $scoreCom = $this->scoreCommunity($sumActAct); // guide: pakai count, bukan %

        // bobot guide
        $wKyd = 0.50;
        $wDpk = 0.15;
        $wRr  = 0.25;
        $wCom = 0.10;

        $total = ($scoreKyd*$wKyd) + ($scoreDpk*$wDpk) + ($scoreRr*$wRr) + ($scoreCom*$wCom);

        // ==========================================================
        // 6) TL SCOPE + KPI TLRO MONTHLY (Stage 1: list TL + nilai KPI)
        // ==========================================================
        $nowMonth = now()->startOfMonth();
        $preferredMode = $period->equalTo($nowMonth) ? 'realtime' : 'eom';
        $fallbackMode  = $preferredMode === 'realtime' ? 'eom' : 'realtime';

        // override via ?mode=realtime|eom
        $reqMode = strtolower(trim((string) $request->query('mode', '')));
        if (in_array($reqMode, ['realtime', 'eom'], true)) {
            $preferredMode = $reqMode;
            $fallbackMode  = $preferredMode === 'realtime' ? 'eom' : 'realtime';
        }

        $kpiTlMap = collect();
        if (!empty($tlIds)) {
            $rows = DB::table('kpi_tlro_monthlies')
                ->whereIn('tlro_id', $tlIds)
                ->whereDate('period', $periodDate)
                ->whereIn('calc_mode', [$preferredMode, $fallbackMode])
                ->orderByRaw("FIELD(calc_mode, ?, ?)", [$preferredMode, $fallbackMode])
                ->get();

            $kpiTlMap = $rows->groupBy('tlro_id')->map(fn($grp) => $grp->first());
        }

        $tlRows = $users
            ->filter(fn($u) => is_string($u->role ?? '') && str_starts_with($u->role, 'TL'))
            ->map(function ($u) use ($kpiTlMap, $periodYm) {
                $k = $kpiTlMap[$u->id] ?? null;

                return (object)[
                    'id'   => $u->id,
                    'name' => $u->name,
                    'role' => $u->role,

                    // ✅ Pastikan route name ini sesuai routes kamu
                    // kalau routes kamu pakai name('tlro.sheet') ya ganti ke 'tlro.sheet'
                    'href' => route('kpi.tlro.sheet', [
                        'user'   => $u->id,
                        'period' => $periodYm,
                    ]),

                    'calc_mode'         => $k->calc_mode ?? null,
                    'ro_count'          => $k->ro_count ?? null,
                    'pi_scope'          => $k->pi_scope ?? null,
                    'stability_index'   => $k->stability_index ?? null,
                    'risk_index'        => $k->risk_index ?? null,
                    'improvement_index' => $k->improvement_index ?? null,
                    'leadership_index'  => $k->leadership_index ?? null,
                    'status_label'      => $k->status_label ?? null,
                ];
            })
            ->sortByDesc(fn($r) => is_null($r->leadership_index) ? -1 : (float)$r->leadership_index)
            ->values()
            ->all();

        // ==========================================================
        // 7) SO RANK (pakai score_total)
        // ==========================================================
        $soRank = collect($soRows)
            ->map(function ($r) use ($users) {
                $u = $users->firstWhere('id', (int)$r->user_id);

                return (object)[
                    'user_id'  => (int)$r->user_id,
                    'name'     => $u?->name ?? ('SO#'.$r->user_id),
                    'os'       => (float)$r->os_disbursement,
                    'rr'       => (float)$r->rr_pct,
                    'activity' => (int)$r->activity_actual,
                    'score'    => (float)$r->score_total,
                ];
            })
            ->sortByDesc('score')
            ->values();

        return view('kpi.kslr.sheet', [
            'me' => $me,
            'periodYmd' => $periodDate,
            'periodLabel' => $periodLabel,

            'kydAchPct' => $kydAchPct,
            'dpkMigPct' => $dpkMigPct,
            'rrPct' => $rrPct,
            'communityPct' => $communityPct,

            'scoreKyd' => $scoreKyd,
            'scoreDpk' => $scoreDpk,
            'scoreRr'  => $scoreRr,
            'scoreCom' => $scoreCom,
            'totalScoreWeighted' => $total,

            'tlRows' => $tlRows,
            'soRank' => $soRank,

            'meta' => [
                'desc_count' => count($descIds),
                'tl_count' => count($tlIds),
                'so_count' => count($soIds),
                'ro_count' => count($roAoCodes),
                'mode' => $preferredMode,
            ],
        ]);
    }

    public function recalc(Request $request)
    {
        // tahap 1: recalc = reload
        $period = trim((string)$request->input('period', ''));

        return redirect()->route('kpi.kslr.sheet', [
            'period' => $period,
            'mode'   => $request->input('mode', null),
        ])->with('status', 'Recalc KSLR sukses (reload).');
    }

    private function resolvePeriodYmd(Request $request): string
    {
        $raw = trim((string)$request->query('period', ''));
        try {
            if ($raw === '') return now()->startOfMonth()->toDateString();
            if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
                return Carbon::createFromFormat('Y-m', $raw)->startOfMonth()->toDateString();
            }
            return Carbon::parse($raw)->startOfMonth()->toDateString();
        } catch (\Throwable $e) {
            return now()->startOfMonth()->toDateString();
        }
    }

    private function resolveRole($u): string
    {
        // aman untuk: enum / string / null
        $raw = method_exists($u, 'roleValue') ? ($u->roleValue() ?? null) : null;
        if ($raw === null) $raw = $u->level ?? '';

        if ($raw instanceof \BackedEnum) $raw = $raw->value;

        return strtoupper(trim((string)$raw));
    }

    // ===== scoring sesuai tabel guide =====
    private function scoreKyd(float $pct): int
    {
        if ($pct < 85) return 1;
        if ($pct < 90) return 2;
        if ($pct < 95) return 3;
        if ($pct < 100) return 4;
        if ($pct <= 100) return 5;
        return 6;
    }

    private function scoreMigrasiDpk(float $pct): int
    {
        if ($pct > 4) return 1;
        if ($pct >= 3) return 2; // 3 - 3.99
        if ($pct >= 2) return 3; // 2 - 2.99
        if ($pct >= 1) return 4; // 1 - 1.99
        if ($pct > 0) return 5;  // <1
        return 6;                // 0
    }

    private function scoreRr(float $pct): int
    {
        if ($pct < 70) return 1;
        if ($pct < 80) return 2;
        if ($pct < 90) return 3;
        if ($pct < 100) return 4;
        return 5;
    }

    private function scoreCommunity(float $count): int
    {
        if ($count <= 1) return 1;
        if ($count == 2) return 2;
        if ($count == 3) return 3;
        if ($count == 4) return 4;
        if ($count == 5) return 5;
        return 6;
    }
}