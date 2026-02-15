<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KpiAoActivityInputController extends Controller
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

        $inputs = DB::table('kpi_ao_activity_inputs')
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        return view('kpi.ao.activity_input', compact('period','users','inputs'));
    }

    public function store(Request $request)
    {
        $period = Carbon::parse($request->string('period'))->startOfMonth()->toDateString();
        $rows = (array) $request->input('rows', []);

        DB::transaction(function () use ($rows, $period) {
            foreach ($rows as $userId => $r) {
                $userId = (int) $userId;

                $payload = [
                    'period' => $period,
                    'user_id' => $userId,
                    'community_actual' => (int)($r['community_actual'] ?? 0),
                    'daily_report_actual' => (int)($r['daily_report_actual'] ?? 0),
                    'updated_at' => now(),
                ];

                $exists = DB::table('kpi_ao_activity_inputs')
                    ->where('period', $period)->where('user_id', $userId)
                    ->exists();

                if ($exists) {
                    DB::table('kpi_ao_activity_inputs')
                        ->where('period', $period)->where('user_id', $userId)
                        ->update($payload);
                } else {
                    $payload['created_at'] = now();
                    DB::table('kpi_ao_activity_inputs')->insert($payload);
                }
            }
        });

        return back()->with('success', 'Input Komunitas & Daily Report tersimpan. Jalankan Recalc AO.');
    }
}
