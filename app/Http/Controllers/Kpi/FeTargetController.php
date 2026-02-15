<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FeTargetController extends Controller
{
    public function index(Request $request)
    {
        // ✅ terima periodYm atau period (biar gak kejebak)
        $periodYm = (string)(
            $request->get('periodYm')
            ?? $request->get('period')
            ?? now()->format('Y-m')
        );

        try {
            $period = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        } catch (\Throwable $e) {
            $period = now()->startOfMonth();
            $periodYm = $period->format('Y-m');
        }

        $periodDate = $period->toDateString();

        // FE users
        $feUsers = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereRaw("UPPER(TRIM(level))='FE'")
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code) <> ''")
            ->orderBy('name')
            ->get();

        // existing targets (period)
        $targetMap = DB::table('kpi_fe_targets')
            ->whereDate('period', $periodDate)
            ->get()
            ->keyBy('fe_user_id');

        return view('kpi.fe.targets.index', [
            'periodYm'  => $periodYm,
            'period'    => $period,
            'feUsers'   => $feUsers,
            'targetMap' => $targetMap,
        ]);
    }

    public function store(Request $request)
    {
        // ✅ terima periodYm atau period (kalau button/URL beda)
        $periodYm = (string)(
            $request->input('periodYm')
            ?? $request->input('period')
            ?? now()->format('Y-m')
        );

        $period = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        $periodDate = $period->toDateString();

        $targets = (array)($request->input('targets') ?? []);
        if (empty($targets)) {
            return back()->with('error', 'Tidak ada data target yang dikirim.');
        }

        $now = now();
        $userId = (int)auth()->id();

        // ambil ao_code FE untuk ids yg dikirim (biar ao_code selalu keisi)
        $feMap = DB::table('users')
            ->whereIn('id', array_map('intval', array_keys($targets)))
            ->pluck('ao_code', 'id');

        $rows = [];

        foreach ($targets as $feId => $t) {
            $feId = (int)$feId;
            if ($feId <= 0) continue;

            $aoCode = trim((string)($feMap[$feId] ?? ''));
            if ($aoCode === '') continue;

            $t = (array)$t;

            $targetOsTurun = (float)($t['target_os_turun_kol2'] ?? 0);
            $targetMigrasi = (float)($t['target_migrasi_npl_pct'] ?? 0.30);
            $targetPenalty = (float)($t['target_penalty_paid'] ?? 0);

            // clamp ringan
            if ($targetMigrasi < 0) $targetMigrasi = 0;
            if ($targetMigrasi > 100) $targetMigrasi = 100;

            $rows[] = [
                'period' => $periodDate,
                'fe_user_id' => $feId,
                'ao_code' => $aoCode,

                'target_os_turun_kol2'   => $targetOsTurun,
                'target_migrasi_npl_pct' => $targetMigrasi,
                'target_penalty_paid'    => $targetPenalty,

                // sesuai tabel kamu
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return back()->with('error', 'Tidak ada baris target valid.');
        }

        DB::table('kpi_fe_targets')->upsert(
            $rows,
            ['period','fe_user_id'],
            [
                'ao_code',
                'target_os_turun_kol2',
                'target_migrasi_npl_pct',
                'target_penalty_paid',
                'updated_at',
            ]
        );

        return redirect()
            ->route('kpi.fe.targets.index', ['periodYm' => $periodYm])
            ->with('success', 'Target KPI FE berhasil disimpan. Silakan jalankan Recalc FE.');
    }
}
