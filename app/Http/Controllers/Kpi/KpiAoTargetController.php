<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KpiAoTargetController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->get('period')
            ? Carbon::parse($request->get('period'))->startOfMonth()->toDateString()
            : now()->startOfMonth()->toDateString();

        $users = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereIn('level', ['AO'])
            ->whereNotNull('ao_code')->where('ao_code','!=','')
            ->orderBy('name')
            ->get();

        $targets = DB::table('kpi_ao_targets')
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        return view('kpi.ao.targets', compact('period','users','targets'));
    }

    public function store(Request $request)
    {
        $period = Carbon::parse($request->string('period'))->startOfMonth()->toDateString();

        $rows = (array) $request->input('rows', []);

        DB::transaction(function () use ($rows, $period) {
            foreach ($rows as $userId => $r) {
                $userId = (int) $userId;

                $aoCode = str_pad(trim((string)($r['ao_code'] ?? '')), 6, '0', STR_PAD_LEFT);

                $payload = [
                    'period' => $period,
                    'user_id' => $userId,
                    'ao_code' => $aoCode,

                    // ✅ target KPI AO versi baru
                    'target_os_disbursement'  => (int)($r['target_os_disbursement'] ?? 0),
                    'target_noa_disbursement' => (int)($r['target_noa_disbursement'] ?? 0),
                    'target_rr'               => (float)($r['target_rr'] ?? 100),
                    'target_community'        => (int)($r['target_community'] ?? 0),
                    'target_daily_report'     => (int)($r['target_daily_report'] ?? 0),

                    // status flow (opsional, menyesuaikan pola kamu)
                    'status' => (string)($r['status'] ?? 'draft'),
                    'updated_at' => now(),
                ];

                $exists = DB::table('kpi_ao_targets')
                    ->where('period', $period)
                    ->where('user_id', $userId)
                    ->exists();

                if ($exists) {
                    DB::table('kpi_ao_targets')
                        ->where('period', $period)
                        ->where('user_id', $userId)
                        ->update($payload);
                } else {
                    $payload['created_at'] = now();
                    DB::table('kpi_ao_targets')->insert($payload);
                }
            }
        });

        return back()->with('success', 'Target AO tersimpan. Jalankan Recalc AO.');
    }
}
