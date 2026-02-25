<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class KblTargetController extends Controller
{
    public function edit(Request $request)
    {
        $me = $request->user();
        abort_unless($me, 403);

        Gate::authorize('kpi-kbl-view');

        $periodYmd = $this->resolvePeriodYmd($request);
        $periodDate = Carbon::parse($periodYmd)->startOfMonth()->toDateString();
        $periodLabel = Carbon::parse($periodDate)->translatedFormat('F Y');

        $row = DB::table('kpi_kbl_targets')
            ->where('kbl_id', (int)$me->id)
            ->whereDate('period', $periodDate)
            ->first();

        return view('kpi.kbl.target_form', [
            'me' => $me,
            'periodDate' => $periodDate,
            'periodLabel' => $periodLabel,
            'row' => $row,
        ]);
    }

    public function upsert(Request $request)
    {
        $me = $request->user();
        abort_unless($me, 403);

        Gate::authorize('kpi-kbl-view');

        $data = $request->validate([
            'period' => ['required','date'],
            'target_os' => ['nullable'],
            'target_npl_pct' => ['nullable','numeric','min:0','max:100'],
            'target_interest_income' => ['nullable'],
            'target_community' => ['nullable','integer','min:0'],
            'meta' => ['nullable','string'],
        ]);

        $periodDate = Carbon::parse($data['period'])->startOfMonth()->toDateString();

        // normalize angka "Rp 1.234.567" -> 1234567
        $toNum = function ($v) {
            $v = (string)($v ?? '');
            $v = preg_replace('/[^\d\-]/', '', $v);
            return (float)($v === '' ? 0 : $v);
        };

        $payload = [
            'kbl_id' => (int)$me->id,
            'period' => $periodDate,
            'target_os' => $toNum($data['target_os'] ?? 0),
            'target_npl_pct' => (float)($data['target_npl_pct'] ?? 0),
            'target_interest_income' => $toNum($data['target_interest_income'] ?? 0),
            'target_community' => (int)($data['target_community'] ?? 0),
            'meta' => !empty($data['meta']) ? $data['meta'] : null,
            'updated_at' => now(),
        ];

        DB::table('kpi_kbl_targets')->updateOrInsert(
            ['kbl_id' => (int)$me->id, 'period' => $periodDate],
            $payload + ['created_at' => now()]
        );

        return redirect()
            ->route('kpi.kbl.sheet', ['period' => Carbon::parse($periodDate)->format('Y-m')])
            ->with('status', 'Target KBL tersimpan.');
    }

    private function resolvePeriodYmd(Request $request): string
    {
        $raw = trim((string)$request->query('period', ''));
        if ($raw === '') return now()->startOfMonth()->toDateString();

        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return Carbon::parse($raw . '-01')->startOfMonth()->toDateString();
        }

        return Carbon::parse($raw)->startOfMonth()->toDateString();
    }
}