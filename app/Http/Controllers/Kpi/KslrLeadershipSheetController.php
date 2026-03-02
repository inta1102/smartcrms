<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Kpi\KpiKslrMonthly;
use App\Services\Kpi\KslrMonthlyBuilder;
use App\Services\Org\OrgScopeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class KslrLeadershipSheetController extends Controller
{
    public function index(
        Request $request,
        OrgScopeService $scope,
        KslrMonthlyBuilder $builder
    ) {
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
        // MODE (realtime/eom) - HARUS SEBELUM LOAD SNAPSHOT
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

        // ==========================================================
        // 1) SCOPE: Ambil descendant (TLRO/TLSO/SO/RO) dari KSLR
        // ==========================================================
        $descIds = $scope->descendantUserIds((int)$me->id, $periodYmd, 'lending', 3);

        // ==========================================================
        // 2) USERS (id, name, ao_code, role)
        // ==========================================================
        $users = User::query()
            ->whereIn('id', $descIds)
            ->get(['id', 'name', 'ao_code', 'level'])
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
                ->select('ao_code', 'total_score_weighted', 'repayment_pct', 'dpk_pct')
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
                ->select('user_id', 'os_disbursement', 'noa_disbursement', 'rr_pct', 'activity_actual', 'score_total')
                ->whereDate('period', $periodDate)
                ->whereIn('user_id', $soIds)
                ->get();

            $soTargets = DB::table('kpi_so_targets')
                ->select('user_id', 'target_os_disbursement', 'target_noa_disbursement', 'target_rr', 'target_activity')
                ->whereDate('period', $periodDate)
                ->whereIn('user_id', $soIds)
                ->get()
                ->keyBy('user_id');
        }

        // ==========================================================
        // 5) LOAD SNAPSHOT (prefer mode) -> fallback -> build
        // ==========================================================
        $row = KpiKslrMonthly::query()
            ->forKslr($me->id)
            ->forPeriod($periodDate)
            ->forMode($preferredMode)
            ->first();

        if (!$row && in_array($fallbackMode, ['realtime', 'eom'], true) && $fallbackMode !== $preferredMode) {
            $row = KpiKslrMonthly::query()
                ->forKslr($me->id)
                ->forPeriod($periodDate)
                ->forMode($fallbackMode)
                ->first();
        }

        if (!$row) {
            $row = $builder->build(
                $me->id,
                $periodDate,
                $preferredMode,
                $scope
            );
        }

        // ==========================================================
        // 6) TL SCOPE + KPI TLRO MONTHLY (Stage 1: list TL + nilai KPI)
        // ==========================================================
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
                    'name'     => $u?->name ?? ('SO#' . $r->user_id),
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

            // dari snapshot kpi_kslr_monthlies
            'kydAchPct'     => (float)($row->kyd_ach_pct ?? 0),
            'dpkMigPct'     => (float)($row->dpk_mig_pct ?? 0),
            'rrPct'         => (float)($row->rr_pct ?? 0),
            'communityPct'  => (float)($row->community_pct ?? 0),

            'scoreKyd' => (float)($row->score_kyd ?? 0),
            'scoreDpk' => (float)($row->score_dpk ?? 0),
            'scoreRr'  => (float)($row->score_rr ?? 0),
            'scoreCom' => (float)($row->score_com ?? 0),

            'totalScoreWeighted' => (float)($row->total_score_weighted ?? 0),

            'tlRows' => $tlRows ?? [],
            'soRank' => $soRank ?? [],

            // meta snapshot
            'meta' => is_string($row->meta ?? null) ? json_decode($row->meta, true) : ($row->meta ?? []),
        ]);
    }

    public function recalc(
        Request $request,
        OrgScopeService $scope,
        KslrMonthlyBuilder $builder
    ) {
        $me = $request->user();
        abort_unless($me, 403);

        Gate::authorize('kpi-kslr-view');

        $period = trim((string)$request->input('period', ''));
        $mode   = strtolower(trim((string)$request->input('mode', 'realtime')));

        if ($mode !== 'realtime' && $mode !== 'eom') $mode = 'realtime';

        // support: '2026-02' atau '2026-02-01'
        try {
            $periodDate = preg_match('/^\d{4}-\d{2}$/', $period)
                ? Carbon::createFromFormat('Y-m', $period)->startOfMonth()->toDateString()
                : Carbon::parse($period)->startOfMonth()->toDateString();
        } catch (\Throwable $e) {
            $periodDate = now()->startOfMonth()->toDateString();
        }

        $builder->build(
            $me->id,
            $periodDate,
            $mode,
            $scope
        );

        return redirect()->route('kpi.kslr.sheet', [
            'period' => substr($periodDate, 0, 7),
            'mode'   => $mode,
        ])->with('status', 'Recalc KSLR berhasil.');
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
        $raw = $u->level ?? null;

        if ($raw instanceof \BackedEnum) {
            $raw = $raw->value;
        }

        return strtoupper(trim((string)($raw ?? '')));
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