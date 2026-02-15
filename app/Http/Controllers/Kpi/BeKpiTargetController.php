<?php

namespace App\Http\Controllers\Kpi;

use App\Models\KpiBeTarget;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BeKpiTargetController
{
    public function index(Request $request)
    {
        $periodYm = (string) $request->query('period', now()->format('Y-m'));

        try {
            $period = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        } catch (\Throwable $e) {
            $period = now()->startOfMonth();
            $periodYm = $period->format('Y-m');
        }

        $periodDate = $period->toDateString(); // YYYY-MM-01

        // sementara: semua user level BE yang punya ao_code
        $beUsers = DB::table('users')
            ->select(['id','name','ao_code','level'])
            ->whereRaw("UPPER(TRIM(level)) = 'BE'")
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code) <> ''")
            ->orderBy('name')
            ->get();

        $targets = KpiBeTarget::query()
            ->where('period', $periodDate)
            ->whereIn('be_user_id', $beUsers->pluck('id')->map(fn($x)=>(int)$x)->all())
            ->get()
            ->keyBy('be_user_id');

        return view('kpi.be.targets.index', compact('periodYm','period','periodDate','beUsers','targets'));
    }

    public function store(Request $request)
    {
        $periodYm = (string) $request->input('period', now()->format('Y-m'));

        try {
            $period = Carbon::createFromFormat('Y-m', $periodYm)->startOfMonth();
        } catch (\Throwable $e) {
            $period = now()->startOfMonth();
            $periodYm = $period->format('Y-m');
        }

        $periodDate = $period->toDateString();

        $data = $request->validate([
            'period' => ['required','string'],
            'targets' => ['required','array'],

            'targets.*.be_user_id' => ['required','integer'],

            'targets.*.target_os_selesai'   => ['nullable','numeric','min:0'],
            'targets.*.target_noa_selesai'  => ['nullable','integer','min:0'],
            'targets.*.target_bunga_masuk'  => ['nullable','numeric','min:0'],
            'targets.*.target_denda_masuk'  => ['nullable','numeric','min:0'],
        ]);

        DB::transaction(function () use ($data, $periodDate) {
            foreach ($data['targets'] as $row) {
                $beUserId = (int) $row['be_user_id'];

                KpiBeTarget::query()->updateOrCreate(
                    ['period' => $periodDate, 'be_user_id' => $beUserId],
                    [
                        'target_os_selesai'   => (float)($row['target_os_selesai'] ?? 0),
                        'target_noa_selesai'  => (int)  ($row['target_noa_selesai'] ?? 0),
                        'target_bunga_masuk'  => (float)($row['target_bunga_masuk'] ?? 0),
                        'target_denda_masuk'  => (float)($row['target_denda_masuk'] ?? 0),
                    ]
                );
            }
        });

        return redirect()
            ->route('kpi.be.targets.index', ['period' => $periodYm])
            ->with('status', 'Target BE berhasil disimpan. Silakan jalankan Recalc BE.');
    }
}
