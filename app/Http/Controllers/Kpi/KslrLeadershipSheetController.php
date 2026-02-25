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

        $periodYmd = $this->resolvePeriodYmd($request);
        $periodLabel = Carbon::parse($periodYmd)->translatedFormat('F Y');

        // 1) Ambil seluruh descendant (TLRO/TLSO/SO/RO) dari KSLR
        $descIds = $scope->descendantUserIds((int)$me->id, $periodYmd, 'lending', 3);

        // 2) Ambil user + role nya (untuk klasifikasi TLRO/SO/RO)
        $users = User::query()
            ->whereIn('id', $descIds)
            ->get(['id','name','ao_code','level'])
            ->map(function ($u) {
                $role = $this->resolveRole($u);
                return (object)[
                    'id' => (int)$u->id,
                    'name' => $u->name,
                    'ao_code' => $u->ao_code ? str_pad(trim((string)$u->ao_code), 6, '0', STR_PAD_LEFT) : null,
                    'role' => $role,
                ];
            });

        $tlIds = $users->filter(fn($u) => str_starts_with($u->role, 'TL'))->pluck('id')->all();
        $soIds = $users->filter(fn($u) => $u->role === 'SO')->pluck('id')->all();
        $roAoCodes = $users->filter(fn($u) => $u->role === 'RO' && !empty($u->ao_code))->pluck('ao_code')->all();

        // 3) Pull KPI RO scope (untuk RR & Migrasi DPK)
        $roRows = [];
        if (!empty($roAoCodes)) {
            $roRows = DB::table('kpi_ro_monthly')
                ->select('ao_code','total_score_weighted','repayment_pct','dpk_pct')
                ->whereDate('period_month', $periodYmd)
                ->whereIn('ao_code', $roAoCodes)
                ->get();
        }

        // 4) Pull KPI SO scope (untuk Achievement KYD + Handling Komunitas)
        $soRows = [];
        $soTargets = [];
        if (!empty($soIds)) {
            $soRows = DB::table('kpi_so_monthlies')
                ->select('user_id','os_disbursement','noa_disbursement','rr_pct','activity_actual','score_total')
                ->whereDate('period', $periodYmd)
                ->whereIn('user_id', $soIds)
                ->get();

            $soTargets = DB::table('kpi_so_targets')
                ->select('user_id','target_os_disbursement','target_noa_disbursement','target_rr','target_activity')
                ->whereDate('period', $periodYmd)
                ->whereIn('user_id', $soIds)
                ->get()
                ->keyBy('user_id');
        }

        // =========================
        // AGREGASI METRIK KSLR
        // =========================

        // (1) Achievement KYD (proxy: OS disbursement SO vs target OS)
        $sumOsAct = (float) collect($soRows)->sum('os_disbursement');
        $sumOsTgt = (float) collect($soTargets)->sum('target_os_disbursement');
        $kydAchPct = $sumOsTgt > 0 ? ($sumOsAct / $sumOsTgt) * 100 : 0;

        // (2) Migrasi DPK (proxy: avg dpk_pct RO scope)
        $dpkMigPct = count($roRows) ? (float) collect($roRows)->avg('dpk_pct') : 0;

        // (3) Repayment Rate (proxy: avg repayment_pct RO scope)
        $rrPct = count($roRows) ? (float) collect($roRows)->avg('repayment_pct') : 0;

        // (4) Handling Komunitas (proxy: activity_actual SO vs target_activity)
        $sumActAct = (float) collect($soRows)->sum('activity_actual');
        $sumActTgt = (float) collect($soTargets)->sum('target_activity');
        $communityPct = $sumActTgt > 0 ? ($sumActAct / $sumActTgt) * 100 : 0;

        // scoring 1..6 sesuai guide yang kamu kirim
        $scoreKyd = $this->scoreKyd($kydAchPct);
        $scoreDpk = $this->scoreMigrasiDpk($dpkMigPct);
        $scoreRr  = $this->scoreRr($rrPct);
        $scoreCom = $this->scoreCommunity($sumActAct); // guide: 1..>5 pakai angka komunitas (bukan %)

        // bobot guide
        $wKyd = 0.50;
        $wDpk = 0.15;
        $wRr  = 0.25;
        $wCom = 0.10;

        $total = ($scoreKyd*$wKyd) + ($scoreDpk*$wDpk) + ($scoreRr*$wRr) + ($scoreCom*$wCom);

        // ranking TLRO (pakai RO scope score avg di bawah TL, tahap 1: hanya tampil TL list)
        
        // ==========================================================
        // TL SCOPE + KPI TLRO MONTHLY
        // ==========================================================

        $period = Carbon::parse($periodYmd)->startOfMonth();
        $periodDate = $period->toDateString();
        $periodYm   = $period->format('Y-m');

        // resolve calc_mode auto + override
        $nowMonth = now()->startOfMonth();
        $preferredMode = $period->equalTo($nowMonth) ? 'realtime' : 'eom';
        $fallbackMode  = $preferredMode === 'realtime' ? 'eom' : 'realtime';

        $reqMode = strtolower(trim((string) $request->query('mode', '')));
        if (in_array($reqMode, ['realtime', 'eom'], true)) {
            $preferredMode = $reqMode;
            $fallbackMode  = $preferredMode === 'realtime' ? 'eom' : 'realtime';
        }

        // TL IDs (pakai yang dari $users)
        $tlIds = collect($users)
            ->filter(fn($u) => is_string($u->role ?? '') && str_starts_with($u->role, 'TL'))
            ->pluck('id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        // KPI TL map
        $kpiTlMap = collect();
        if (!empty($tlIds)) {
            $rows = DB::table('kpi_tlro_monthlies')
                ->whereIn('tlro_id', $tlIds)
                ->whereDate('period', $periodDate)
                ->whereIn('calc_mode', [$preferredMode, $fallbackMode])
                ->orderByRaw("FIELD(calc_mode, ?, ?)", [$preferredMode, $fallbackMode])
                ->get();

            $kpiTlMap = $rows
                ->groupBy('tlro_id')
                ->map(fn($grp) => $grp->first());
        }

        // TL rows (include href)
        $tlRows = collect($users)
            ->filter(fn($u) => is_string($u->role ?? '') && str_starts_with($u->role, 'TL'))
            ->map(function ($u) use ($kpiTlMap, $periodYm) {
                $k = $kpiTlMap[$u->id] ?? null;

                return (object)[
                    'id'   => $u->id,
                    'name' => $u->name,
                    'role' => $u->role,

                    // ✅ pakai periodYm biar konsisten & nggak parse lagi
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
                    
        // ranking SO (pakai score_total)
        $soRank = collect($soRows)->map(function ($r) use ($users) {
            $u = $users->firstWhere('id', (int)$r->user_id);
            return (object)[
                'user_id' => (int)$r->user_id,
                'name' => $u?->name ?? ('SO#'.$r->user_id),
                'os' => (float)$r->os_disbursement,
                'rr' => (float)$r->rr_pct,
                'activity' => (int)$r->activity_actual,
                'score' => (float)$r->score_total,
            ];
        })->sortByDesc('score')->values();

        return view('kpi.kslr.sheet', [
            'me' => $me,
            'periodYmd' => $periodYmd,
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

            // debug info kalau butuh
            'meta' => [
                'desc_count' => count($descIds),
                'tl_count' => count($tlIds),
                'so_count' => count($soIds),
                'ro_count' => count($roAoCodes),
            ],
        ]);
    }

    public function recalc(Request $request)
    {
        // tahap 1: recalc = reload (nanti kalau sudah ada tabel kslr_monthlies baru kita persist)
        return redirect()->route('kpi.kslr.sheet', ['period' => $request->input('period')]);
    }

    private function resolvePeriodYmd(Request $request): string
    {
        $raw = trim((string)$request->query('period', ''));
        try {
            if ($raw === '') return now()->startOfMonth()->toDateString();
            if (preg_match('/^\d{4}-\d{2}$/', $raw)) return Carbon::createFromFormat('Y-m', $raw)->startOfMonth()->toDateString();
            return Carbon::parse($raw)->startOfMonth()->toDateString();
        } catch (\Throwable $e) {
            return now()->startOfMonth()->toDateString();
        }
    }

    private function resolveRole($u): string
    {
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
        if ($pct <= 100) return 5; // tepat 100
        return 6; // >100
    }

    private function scoreMigrasiDpk(float $pct): int
    {
        if ($pct > 4) return 1;
        if ($pct >= 3) return 2;      // 3 - 3.99
        if ($pct >= 2) return 3;      // 2 - 2.99
        if ($pct >= 1) return 4;      // 1 - 1.99
        if ($pct > 0) return 5;       // <1
        return 6;                     // 0%
    }

    private function scoreRr(float $pct): int
    {
        if ($pct < 70) return 1;
        if ($pct < 80) return 2;
        if ($pct < 90) return 3;
        if ($pct < 100) return 4;
        return 5; // ≥100 (kalau mau 6 khusus >100 juga bisa)
    }

    private function scoreCommunity(float $count): int
    {
        // guide: 1..>5
        if ($count <= 1) return 1;
        if ($count == 2) return 2;
        if ($count == 3) return 3;
        if ($count == 4) return 4;
        if ($count == 5) return 5;
        return 6; // >5
    }
}