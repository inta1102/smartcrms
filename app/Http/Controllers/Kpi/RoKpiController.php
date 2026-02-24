<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller; // ✅ ini yang benar
use App\Models\KpiRoMonthly;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoKpiController extends Controller
{
    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $role = strtoupper($me->roleValue());

        $roIdQ = (int) request()->query('ro_id', 0);
        $subjectUser = $me;

        if ($roIdQ > 0) {

            abort_unless(in_array($role, ['TLRO','KSLR','KBL','ADMIN','SUPERADMIN'], true), 403);

            $subjectUser = \App\Models\User::findOrFail($roIdQ);

            abort_unless(strtoupper($subjectUser->roleValue()) === 'RO', 403);

            // optional: cek scope TLRO -> RO
            if ($role === 'TLRO') {
                $isInScope = DB::table('org_assignments')
                    ->where('leader_id', $me->id)
                    ->where('user_id', $subjectUser->id)
                    ->where('active', 1)
                    ->exists();

                abort_unless($isInScope, 403);
            }
        }
        // defaults
        $period = $request->input('period', now()->startOfMonth()->toDateString()); // YYYY-MM-01
        $mode   = $request->input('mode', 'realtime'); // realtime|eom
        $branch = $request->input('branch'); // optional
        $q      = trim((string) $request->input('q', '')); // search name/ao_code

        // normalize
        try {
            $periodMonth = Carbon::parse($period)->startOfMonth()->toDateString();
        } catch (\Throwable $e) {
            $periodMonth = now()->startOfMonth()->toDateString();
        }
        $mode = in_array($mode, ['realtime','eom'], true) ? $mode : 'realtime';

        // branches dropdown (ambil dari kpi table untuk period+mode)
        $branches = KpiRoMonthly::query()
            ->whereDate('period_month', $periodMonth)
            ->where('calc_mode', $mode)
            ->whereNotNull('branch_code')->where('branch_code','!=','')
            ->select('branch_code')
            ->distinct()
            ->orderBy('branch_code')
            ->pluck('branch_code')
            ->all();

        // main ranking query
        $rowsQ = KpiRoMonthly::query()
            ->from('kpi_ro_monthly as k')
            // join users by ao_code untuk ambil nama RO/AO
            ->leftJoin('users as u', function ($join) {
                $join->on(DB::raw('TRIM(u.ao_code)'), '=', DB::raw('TRIM(k.ao_code)'));
            })
            ->whereDate('k.period_month', $periodMonth)
            ->where('k.calc_mode', $mode);

        if ($branch) $rowsQ->where('k.branch_code', $branch);

        if ($q !== '') {
            $rowsQ->where(function ($w) use ($q) {
                $w->where('k.ao_code', 'like', "%{$q}%")
                  ->orWhere('u.name', 'like', "%{$q}%");
            });
        }

        $rows = $rowsQ
            ->select([
                'k.*',
                DB::raw("COALESCE(NULLIF(TRIM(u.name),''), CONCAT('AO ', k.ao_code)) AS ro_name"),
            ])
            ->orderByDesc('k.total_score_weighted')
            ->orderBy('k.ao_code')
            ->paginate(50)
            ->withQueryString();

        // quick summary cards
        $summary = KpiRoMonthly::query()
            ->whereDate('period_month', $periodMonth)
            ->where('calc_mode', $mode)
            ->when($branch, fn($qq) => $qq->where('branch_code', $branch))
            ->selectRaw('COUNT(*) as cnt')
            ->selectRaw('AVG(total_score_weighted) as avg_score')
            ->selectRaw('SUM(CASE WHEN dpk_migrasi_count > 0 THEN 1 ELSE 0 END) as ao_with_migrasi')
            ->first();

        return view('kpi.ro.index', compact(
            'periodMonth','mode','branch','q','branches','rows','summary'
        ));
    }

    public function show(Request $request, string $ao)
    {
        $period = $request->input('period', now()->startOfMonth()->toDateString());
        $mode   = $request->input('mode', 'realtime');

        $periodMonth = Carbon::parse($period)->startOfMonth()->toDateString();
        $mode = in_array($mode, ['realtime','eom'], true) ? $mode : 'realtime';

        $row = KpiRoMonthly::query()
            ->whereDate('period_month', $periodMonth)
            ->where('calc_mode', $mode)
            ->where('ao_code', $ao)
            ->firstOrFail();

        $userName = DB::table('users')
            ->where('ao_code', $ao)
            ->value('name');

        return view('kpi.ro.show', [
            'periodMonth' => $periodMonth,
            'mode' => $mode,
            'ao' => $ao,
            'name' => $userName ?: ('AO '.$ao),
            'row' => $row,
        ]);
    }
}
