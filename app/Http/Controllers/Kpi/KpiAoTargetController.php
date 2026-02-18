<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KpiAoTargetController extends Controller
{
   
    private function normPeriod(?string $period): string
    {
        $p = trim((string)$period);

        // default: bulan ini
        if ($p === '') {
            return now()->startOfMonth()->format('Y-m-d');
        }

        // ✅ terima YYYY-MM
        if (preg_match('/^\d{4}-\d{2}$/', $p)) {
            try {
                return Carbon::createFromFormat('Y-m', $p)->startOfMonth()->format('Y-m-d');
            } catch (\Throwable $e) {
                abort(422, 'Format period tidak valid. Gunakan YYYY-MM atau YYYY-MM-DD');
            }
        }

        // ✅ terima YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $p)) {
            try {
                return Carbon::createFromFormat('Y-m-d', $p)->startOfMonth()->format('Y-m-d');
            } catch (\Throwable $e) {
                abort(422, 'Format period tidak valid. Gunakan YYYY-MM atau YYYY-MM-DD');
            }
        }

        // selain itu → 422 (jangan 404)
        abort(422, 'Format period harus YYYY-MM atau YYYY-MM-DD');
    }


    private function toIntMoney($v): int
    {
        // aman untuk: "Rp 1.500.000.000" / "1,500,000,000" / "1500000000"
        $s = preg_replace('/[^\d\-]/', '', (string)($v ?? '0'));
        if ($s === '' || $s === '-') return 0;
        return (int)$s;
    }

    private function toInt($v): int
    {
        if ($v === null || $v === '') return 0;
        return (int)$v;
    }

    private function toPct($v, float $default = 100.0): float
    {
        if ($v === null || $v === '') return $default;
        $x = (float)$v;
        if ($x < 0) $x = 0;
        if ($x > 100) $x = 100;
        return $x;
    }

    public function index(Request $request)
    {
        $periodYmd = $this->normPeriod($request->get('period'));

        $users = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->where('level', 'AO')
            ->whereNotNull('ao_code')->where('ao_code','!=','')
            ->orderBy('name')
            ->get();

        $targets = DB::table('kpi_ao_targets')
            ->where('period', $periodYmd)
            ->get()
            ->keyBy('user_id');

        return view('kpi.ao.targets.index', compact('periodYmd','users','targets'));
    }

    public function store(Request $request)
    {
        $periodYmd = $this->normPeriod($request->string('period'));

        // ✅ SESUAI blade: name="targets[userId][field]"
        $rows = (array) $request->input('targets', []);

        DB::transaction(function () use ($rows, $periodYmd) {
            foreach ($rows as $userId => $r) {
                $userId = (int)$userId;

                // ✅ ambil ao_code dari master users (lebih valid)
                $aoCodeDb = (string) DB::table('users')->where('id', $userId)->value('ao_code');
                $aoCode = str_pad(trim($aoCodeDb), 6, '0', STR_PAD_LEFT);

                $payload = [
                    'period'  => $periodYmd,
                    'user_id' => $userId,
                    'ao_code' => $aoCode,

                    // ✅ target AO UMKM (baru)
                    'target_os_disbursement'  => $this->toIntMoney($r['target_os_disbursement'] ?? 0),
                    'target_noa_disbursement' => $this->toInt($r['target_noa_disbursement'] ?? 0),
                    'target_rr'               => $this->toPct($r['target_rr'] ?? null, 90.0),
                    'target_community'        => $this->toInt($r['target_community'] ?? 0),
                    'target_daily_report'     => $this->toInt($r['target_daily_report'] ?? 0),

                    // optional flow
                    'status'     => (string)($r['status'] ?? 'draft'),
                    'updated_at' => now(),
                ];

                DB::table('kpi_ao_targets')->updateOrInsert(
                    ['period' => $periodYmd, 'user_id' => $userId],
                    $payload + ['created_at' => now()]
                );
            }
        });

        return back()->with('success', 'Target AO tersimpan. Jalankan Recalc AO.');
    }
}
