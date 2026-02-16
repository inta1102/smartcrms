<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\Kpi\KpiSoCommunityInput;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SoCommunityInputController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->filled('period')
            ? Carbon::parse($request->string('period'))->startOfMonth()->toDateString()
            : now()->startOfMonth()->toDateString();

        // ambil user SO (pola mKPI: where level='SO' dan ao_code not null)
        $users = DB::table('users')
            ->select(['id', 'name', 'ao_code', 'level'])
            ->where('level', 'SO')
            ->whereNotNull('ao_code')
            ->where('ao_code', '!=', '')
            ->orderBy('name')
            ->get();

        $inputs = KpiSoCommunityInput::query()
            ->where('period', $period)
            ->get()
            ->keyBy('user_id');

        $targetsByUserId = DB::table('kpi_so_targets')
            ->where('period', Carbon::parse($period)->startOfMonth()->toDateString())
            ->get()
            ->keyBy('user_id');

        return view('kpi.so.community_input.index', compact('period','users','inputs','targetsByUserId'));


        // return view('kpi.so.community_input.index', [
        //     'period' => $period,
        //     'users'  => $users,
        //     'inputs' => $inputs,
        // ]);
    }

    public function store(Request $request)
    {
        $period = Carbon::parse($request->string('period'))->startOfMonth()->toDateString();

        $validated = $request->validate([
            'period' => ['required', 'date'],
            'items'  => ['required', 'array'],
            'items.*.user_id' => ['required', 'integer', 'min:1'],
            'items.*.handling_actual' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'items.*.os_adjustment' => ['nullable', 'integer', 'min:0'], // rupiah (simpan tanpa titik/koma)
        ]);

        $actorId = (int) auth()->id();

        $rows = [];
        foreach ($validated['items'] as $it) {
            $rows[] = [
                'period' => $period,
                'user_id' => (int) $it['user_id'],
                'handling_actual' => (int) ($it['handling_actual'] ?? 0),
                'os_adjustment' => (int) ($it['os_adjustment'] ?? 0),

                'updated_by' => $actorId,
                'updated_at' => now(),

                // untuk insert baru
                'created_by' => $actorId,
                'created_at' => now(),
            ];
        }

        DB::transaction(function () use ($rows) {
            DB::table('kpi_so_community_inputs')->upsert(
                $rows,
                ['period', 'user_id'],
                ['handling_actual', 'os_adjustment', 'updated_by', 'updated_at']
            );
        });

        /**
         * Trigger recalc.
         * Kamu bisa ganti sesuai pola mKPI kamu:
         * - dispatch Job (recommended)
         * - atau panggil service recalc langsung
         */
        // \App\Jobs\Kpi\RecalcSoKpiJob::dispatch($period)->onQueue('kpi');

        return redirect()
            ->route('kpi.so.community_input.index', ['period' => $period])
            ->with('status', 'OK. Input tersimpan. Jalankan Recalc SO untuk update skor/pct.');
    }
}
