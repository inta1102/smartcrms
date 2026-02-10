<?php

namespace App\Http\Controllers\Kpi;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SoKpiTargetController
{
    public function index(Request $request)
    {
        $periodYm  = $request->query('period', now()->format('Y-m'));
        $periodYmd = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth()->toDateString();

        // ✅ daftar user SO
        $users = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereIn('level', ['SO'])
            ->whereNotNull('ao_code')
            ->where('ao_code','!=','')
            ->orderBy('name')
            ->get();

        // ✅ target existing utk periode tsb
        $targets = DB::table('kpi_so_targets')
            ->where('period', $periodYmd)
            ->get()
            ->keyBy('user_id');

        // merge (biar blade gampang)
        $items = $users->map(function($u) use ($targets){
            $t = $targets->get($u->id);

            return (object) [
                'user_id' => (int)$u->id,
                'name'    => (string)$u->name,
                'ao_code' => str_pad(trim((string)($u->ao_code ?? '')), 6, '0', STR_PAD_LEFT),
                'level'   => (string)$u->level,

                // default target (kalau belum ada row)
                'target_os_disbursement'  => (int)($t->target_os_disbursement ?? 0),
                'target_noa_disbursement' => (int)($t->target_noa_disbursement ?? 0),
                'target_rr'               => (float)($t->target_rr ?? 100),
                'target_activity'         => (int)($t->target_activity ?? 0),
            ];
        });

        return view('kpi.so.targets.index', [
            'periodYm'  => $periodYm,
            'periodYmd' => $periodYmd,
            'items'     => $items,
        ]);
    }

    public function store(Request $request)
    {
        $periodYmd = Carbon::parse($request->input('period'))->startOfMonth()->toDateString();

        $rows = $request->input('rows', []);
        if (!is_array($rows)) $rows = [];

        DB::transaction(function () use ($rows, $periodYmd) {
            foreach ($rows as $userId => $r) {
                $userId = (int)$userId;
                if ($userId <= 0) continue;

                $os  = (int)($r['target_os_disbursement'] ?? 0);
                $noa = (int)($r['target_noa_disbursement'] ?? 0);
                $rr  = (float)($r['target_rr'] ?? 100);
                $act = (int)($r['target_activity'] ?? 0);

                // normalisasi minimal
                if ($os < 0) $os = 0;
                if ($noa < 0) $noa = 0;
                if ($rr < 0) $rr = 0;
                if ($rr > 100) $rr = 100;
                if ($act < 0) $act = 0;

                $key = ['period' => $periodYmd, 'user_id' => $userId];

                $payload = [
                    'target_os_disbursement'  => $os,
                    'target_noa_disbursement' => $noa,
                    'target_rr'               => $rr,
                    'target_activity'         => $act,
                    'updated_at'              => now(),
                ];

                $exists = DB::table('kpi_so_targets')->where($key)->exists();
                if ($exists) {
                    DB::table('kpi_so_targets')->where($key)->update($payload);
                } else {
                    $payload['created_at'] = now();
                    DB::table('kpi_so_targets')->insert(array_merge($key, $payload));
                }
            }
        });

        return redirect()
            ->route('kpi.so.targets.index', ['period' => Carbon::parse($periodYmd)->format('Y-m')])
            ->with('success', 'Target SO berhasil disimpan.');
    }
}
