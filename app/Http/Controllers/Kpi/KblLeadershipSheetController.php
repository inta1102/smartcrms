<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Kpi\KblMonthlyBuilder;
use App\Services\Org\OrgScopeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Enums\UserRole;


class KblLeadershipSheetController extends Controller
{
    public function index(Request $request, OrgScopeService $scope, KblMonthlyBuilder $builder)
    {
        $me = $request->user();
        abort_unless($me, 403);

        Gate::authorize('kpi-kbl-view');

        // ==========================================================
        // 0) PERIOD
        // ==========================================================
        $periodYmd   = $this->resolvePeriodYmd($request); // YYYY-MM-01
        $period      = Carbon::parse($periodYmd)->startOfMonth();
        $periodDate  = $period->toDateString();          // YYYY-MM-01
        $periodLabel = $period->translatedFormat('F Y');

        // ==========================================================
        // 1) MODE (auto + override)
        // ==========================================================
        $nowMonth = now()->startOfMonth();
        $autoMode = $period->equalTo($nowMonth) ? 'realtime' : 'eom';

        $mode = $period->equalTo($nowMonth) ? 'realtime' : 'eom';

        // ==========================================================
        // 2) LEADER LIST (optional UI only)
        //    - Ini boleh pakai scope untuk tampilkan TLUM/KSLR/KSBE/KSFE
        //    - Tapi KPI KBL TIDAK boleh bergantung dari sini
        // ==========================================================
        $descIds = $scope->descendantUserIds((int)$me->id, $periodDate, 'lending', 3);

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

        $leaderRows = $users
            ->filter(fn($u) => in_array($u->role, ['TLUM', 'KSLR', 'KSBE', 'KSFE'], true))
            ->values();

        // ==========================================================
        // 3) BUILD / UPSERT monthlies (GLOBAL, NO SCOPE)
        //    - scopeAoCodes kosong => builder harus treat sebagai GLOBAL
        // ==========================================================
        $payload = $builder->build(
            kblId: (int)$me->id,
            periodYmd: $periodDate,
            scopeAoCodes: [],     // ✅ GLOBAL
            reqMode: $mode
        );

        // ambil row monthlies yang tersimpan (biar konsisten)
        $kblRow = DB::table('kpi_kbl_monthlies')
            ->where('kbl_id', (int)$me->id)
            ->whereDate('period', $periodDate)
            ->where('calc_mode', $mode)
            ->first();

        // target row
        $targetRow = DB::table('kpi_kbl_targets')
            ->where('kbl_id', (int)$me->id)
            ->whereDate('period', $periodDate)
            ->first();

        // ==========================================================
        // 4) BADGE HELPERS (UI)
        // ==========================================================
        $scoreBadge = function ($score) {
            $s = (float)($score ?? 0);
            if ($s >= 5) return ['On Track', 'bg-emerald-100 text-emerald-800 border-emerald-200'];
            if ($s >= 3) return ['Warning', 'bg-amber-100 text-amber-800 border-amber-200'];
            return ['Critical', 'bg-rose-100 text-rose-800 border-rose-200'];
        };

        return view('kpi.kbl.sheet', [
            'me'          => $me,
            'periodYmd'   => $periodDate,
            'periodLabel' => $periodLabel,
            'mode'        => $mode,

            'kblRow'      => $kblRow,
            'targetRow'   => $targetRow,

            'leaderRows'  => $leaderRows,

            'scoreBadge'  => $scoreBadge,

            'meta' => [
                // UI-only scope info
                'desc_count'     => count($descIds),
                'leaders_count'  => $leaderRows->count(),

                // ✅ KPI GLOBAL (bukan scope ao)
                'scope_ao_count' => 0,
                'auto_mode'      => $autoMode,
            ],
        ]);
    }

    // =============================
    // Helpers
    // =============================
    private function resolvePeriodYmd(Request $request): string
    {
        $raw = trim((string)$request->query('period', ''));
        if ($raw === '') return now()->startOfMonth()->toDateString();

        // support "YYYY-MM" dari input month
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) return Carbon::parse($raw . '-01')->startOfMonth()->toDateString();

        // support "YYYY-MM-01"
        return Carbon::parse($raw)->startOfMonth()->toDateString();
    }


    private function resolveRole($u): string
    {
        // users.level bisa string / enum UserRole
        $raw = $u->level ?? null;

        // kalau enum backed (punya ->value)
        if ($raw instanceof \BackedEnum) {
            $lvl = strtoupper(trim((string)$raw->value));
        }
        // kalau enum non-backed (punya ->name)
        elseif ($raw instanceof \UnitEnum) {
            $lvl = strtoupper(trim((string)$raw->name));
        }
        // kalau string biasa
        else {
            $lvl = strtoupper(trim((string)$raw));
        }

        // mapping ke role yang dipakai sheet
        // (sesuaikan kalau kamu punya TLRO/TLSO/KSBE/KSFE/TLUM dll)
        $map = [
            'KBL'  => 'KBL',
            'KSLR' => 'KSLR',
            'KSBE' => 'KSBE',
            'KSFE' => 'KSFE',
            'TLUM' => 'TLUM',
            'TLRO' => 'TLRO',
            'TLSO' => 'TLSO',
            'RO'   => 'RO',
            'SO'   => 'SO',
            'AO'   => 'AO',
            'BE'   => 'BE',
            'FE'   => 'FE',
        ];

        // fallback: kalau role kamu pakai prefix "TL..." atau "KS..."
        if (isset($map[$lvl])) return $map[$lvl];

        if (str_starts_with($lvl, 'TL')) return $lvl;
        if (str_starts_with($lvl, 'KS')) return $lvl;

        return $lvl !== '' ? $lvl : 'UNKNOWN';
    }

    public function recalc(Request $request, OrgScopeService $scope, KblMonthlyBuilder $builder)
    {
        $me = $request->user();
        abort_unless($me, 403);

        Gate::authorize('kpi-kbl-view');

        // period: support query/input "YYYY-MM" dari hidden field
        // resolvePeriodYmd kamu sudah handle "YYYY-MM" -> YYYY-MM-01
        $periodYmd  = $this->resolvePeriodYmd($request);      // YYYY-MM-01
        $period     = Carbon::parse($periodYmd)->startOfMonth();
        $periodDate = $period->toDateString();                // YYYY-MM-01

        // ==========================================================
        // MODE AUTO (tanpa override)
        // - bulan ini => realtime
        // - bulan lalu dst => eom
        // ==========================================================
        $nowMonth = now()->startOfMonth();
        $mode = $period->equalTo($nowMonth) ? 'realtime' : 'eom';

        // ==========================================================
        // SCOPE: KBL melihat TLUM, KSLR, KSBE, KSFE (desc level 3)
        // ==========================================================
        $descIds = $scope->descendantUserIds((int)$me->id, $periodDate, 'lending', 3);

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

        $scopeAoCodes = $users
            ->filter(fn($u) => !empty($u->ao_code))
            ->pluck('ao_code')
            ->unique()
            ->values()
            ->all();

        // ==========================================================
        // DO BUILD (UPSERT)
        // ==========================================================
        $builder->build(
            kblId: (int)$me->id,
            periodYmd: $periodDate,
            scopeAoCodes: $scopeAoCodes,
            reqMode: $mode
        );

        // redirect balik ke sheet (tanpa mode)
        return redirect()
            ->route('kpi.kbl.sheet', [
                'period' => $period->format('Y-m'),
            ])
            ->with('status', 'Recalc KBL sukses.');
    }
}