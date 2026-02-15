<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\KpiRoTarget;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;

class KpiRoTargetController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('manageRoTargets');

        $periodYm = $request->query('period', now()->format('Y-m'));
        $period   = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        $periodDate = $period->toDateString();

        // list RO
        $ros = User::query()
            ->where('level', 'RO')
            ->whereNotNull('ao_code')->where('ao_code', '!=', '')
            ->orderBy('name')
            ->get(['id','name','ao_code','level']);

        // existing targets map by ao_code
        $targets = KpiRoTarget::query()
            ->whereDate('period', $periodDate)
            ->get()
            ->keyBy('ao_code');

        // default values (buat placeholder + fallback)
        $defaults = [
            'target_topup'  => 750_000_000,
            'target_noa'    => 2,
            'target_rr_pct' => 100.00, // kalau mau rr selalu dianggap target 100
            'target_dpk_pct'=> 0.00,
        ];

        return view('kpi.ro.targets.index', compact(
            'periodYm','period','ros','targets','defaults'
        ));
    }

    public function store(Request $request)
    {
        $this->authorize('manageRoTargets');

        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],

            // arrays by ao_code
            'target_topup'   => ['array'],
            'target_noa'     => ['array'],
            'target_rr_pct'  => ['array'],
            'target_dpk_pct' => ['array'],
        ]);

        $period = Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth()->toDateString();

        $topups = (array)($data['target_topup'] ?? []);
        $noas   = (array)($data['target_noa'] ?? []);
        $rrs    = (array)($data['target_rr_pct'] ?? []);
        $dpks   = (array)($data['target_dpk_pct'] ?? []);

        // whitelist RO ao_code biar aman
        $validAo = User::query()
            ->where('level', 'RO')
            ->whereNotNull('ao_code')->where('ao_code','!=','')
            ->pluck('ao_code')
            ->map(fn($x) => (string)$x)
            ->flip();

        $saved = 0;

        foreach ($validAo as $aoCode => $_) {
            // ambil input (kalau tidak ada, biarkan null → fallback default di sheet/builder)
            $tTopup = array_key_exists($aoCode, $topups) ? (float) preg_replace('/[^\d.]/', '', (string)$topups[$aoCode]) : null;
            $tNoa   = array_key_exists($aoCode, $noas)   ? (int)   $noas[$aoCode] : null;
            $tRr    = array_key_exists($aoCode, $rrs)    ? (float) $rrs[$aoCode]  : null;
            $tDpk   = array_key_exists($aoCode, $dpks)   ? (float) $dpks[$aoCode] : null;

            // kalau semuanya null/kosong, skip (biar gak bikin row sampah)
            $allNull = ($tTopup === null && $tNoa === null && $tRr === null && $tDpk === null);
            if ($allNull) continue;

            KpiRoTarget::query()->updateOrCreate(
                ['period' => $period, 'ao_code' => $aoCode],
                [
                    'target_topup'   => $tTopup,
                    'target_noa'     => $tNoa,
                    'target_rr_pct'  => $tRr,
                    'target_dpk_pct' => $tDpk,
                    'updated_by'     => (int) auth()->id(),
                ]
            );

            $saved++;
        }

        return back()->with('status', "Target KPI RO tersimpan. updated={$saved}");
    }
}
