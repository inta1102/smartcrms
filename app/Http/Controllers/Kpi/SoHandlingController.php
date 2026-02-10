<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SoHandlingController extends Controller
{
    private const ROLES = ['TLL','TLR','TLRO','TLSO','TLFE','TLBE','TLUM','KSR','KSL','KBL'];

    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);
        abort_unless($me->hasAnyRole(self::ROLES), 403);

        $period = $request->filled('period')
            ? Carbon::parse($request->get('period'))->startOfMonth()->toDateString()
            : now()->startOfMonth()->toDateString();

        // list SO users (sesuaikan bila level SO saja)
        $users = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereNotNull('ao_code')
            ->where('ao_code','!=','')
            ->whereIn('level', ['SO'])
            ->orderBy('name')
            ->get();

        // targets SO per user
        $targets = DB::table('kpi_so_targets')
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        // monthlies per user (untuk ambil actual yg sudah pernah diinput)
        $monthlies = DB::table('kpi_so_monthlies')
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        return view('kpi.so.handling.index', [
            'period'   => $period,
            'users'    => $users,
            'targets'  => $targets,
            'monthlies'=> $monthlies,
        ]);
    }

    public function save(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);
        abort_unless($me->hasAnyRole(self::ROLES), 403);

        $data = $request->validate([
            'period' => ['required', 'date'],
            'rows'   => ['required', 'array'],
            'rows.*.user_id'        => ['required', 'integer'],
            'rows.*.activity_actual'=> ['nullable', 'integer', 'min:0'],
        ]);

        $period = Carbon::parse($data['period'])->startOfMonth()->toDateString();

        DB::transaction(function () use ($period, $data, $me) {
            foreach ($data['rows'] as $row) {
                $userId = (int) $row['user_id'];
                $actual = (int) ($row['activity_actual'] ?? 0);

                // cari user + ao_code (biar row monthlies lengkap)
                $u = DB::table('users')->select(['id','ao_code'])->where('id', $userId)->first();
                if (!$u) continue;

                $key = ['period' => $period, 'user_id' => $userId];

                // pastikan row monthlies ada
                $exists = DB::table('kpi_so_monthlies')->where($key)->exists();

                if ($exists) {
                    DB::table('kpi_so_monthlies')->where($key)->update([
                        'activity_actual' => $actual,
                        'updated_at'      => now(),
                    ]);
                } else {
                    // create minimal row, field lain default 0
                    DB::table('kpi_so_monthlies')->insert([
                        'period'          => $period,
                        'user_id'         => $userId,
                        'ao_code'         => (string)($u->ao_code ?? ''),
                        'activity_actual' => $actual,

                        // biar tidak null (sesuai schema default)
                        'os_disbursement'       => 0,
                        'noa_disbursement'      => 0,
                        'rr_due_count'          => 0,
                        'rr_paid_ontime_count'  => 0,
                        'rr_pct'                => 0,
                        'activity_target'       => 0,
                        'activity_pct'          => 0,
                        'is_final'              => 0,
                        'score_os'              => 0,
                        'score_noa'             => 0,
                        'score_rr'              => 0,
                        'score_activity'        => 0,
                        'score_total'           => 0,
                        'created_at'            => now(),
                        'updated_at'            => now(),
                    ]);
                }
            }
        });

        return back()->with('status', 'Realisasi Handling Komunitas berhasil disimpan. Silakan Recalc SO untuk update skor.');
    }
}
