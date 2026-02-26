<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\KpiRoManualActual;
use App\Models\OrgAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RoManualNoaController extends Controller
{
    public function edit(Request $request)
    {
        Gate::authorize('kpi-ro-noa-manual-edit');

        // period: support 2026-02 / 2026-02-01
        $raw = trim((string)$request->query('period', ''));
        if ($raw === '') {
            $period = now()->startOfMonth();
        } elseif (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $period = Carbon::createFromFormat('Y-m', $raw)->startOfMonth();
        } else {
            $period = Carbon::parse($raw)->startOfMonth();
        }
        $periodDate = $period->toDateString();

        $me = auth()->user();
        $role = strtoupper((string)($me->roleValue() ?? ''));

        // =========================
        // Helper: filter active assignment by periodDate
        // =========================
        $applyActiveScope = function ($q) use ($periodDate) {
            $q->where('is_active', 1)
            ->whereDate('effective_from', '<=', $periodDate)
            ->where(function ($w) use ($periodDate) {
                $w->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $periodDate);
            });
        };

        // =========================
        // Base query: ONLY RO + ao_code valid
        // (aman kalau RO disimpan di level atau role_value)
        // =========================
        $roQuery = User::query()
            ->whereNotNull('ao_code')
            ->whereRaw("TRIM(ao_code) <> ''")
            ->whereRaw("LPAD(TRIM(ao_code),6,'0') <> '000000'")
            ->where(function ($q) {
                $q->whereRaw("UPPER(TRIM(COALESCE(level,''))) = 'RO'")
                ->orWhereRaw("UPPER(TRIM(COALESCE(level_role,''))) = 'RO'");
            });

        // =========================
        // Scope by role:
        // - KBL : semua RO
        // - TLRO: RO bawahan langsung
        // - KSLR: RO sampai level 2 (via TLRO)
        // =========================
        if ($role !== 'KBL') {
            $uid = (int)$me->id;

            // 1) direct subordinate user_ids
            $directIds = DB::table('org_assignments')
                ->where('leader_id', $uid)
                ->tap($applyActiveScope)
                ->pluck('user_id')
                ->unique()
                ->values()
                ->all();

            $allIds = $directIds;

            // 2) kalau KSLR -> ambil level 2 (bawahan dari direct)
            if ($role === 'KSLR' && count($directIds) > 0) {
                $secondIds = DB::table('org_assignments')
                    ->whereIn('leader_id', $directIds)
                    ->tap($applyActiveScope)
                    ->pluck('user_id')
                    ->unique()
                    ->values()
                    ->all();

                $allIds = array_values(array_unique(array_merge($directIds, $secondIds)));
            }

            if (count($allIds) > 0) {
                $roQuery->whereIn('id', $allIds);
            } else {
                $roQuery->whereRaw('1=0');
            }
        }

        $ros = $roQuery
            ->selectRaw("id, name, LPAD(TRIM(ao_code),6,'0') as ao_code")
            ->orderBy('name')
            ->get();

        // target NOA map (ambil dari kpi_ro_targets kolom target_noa)
        $targets = DB::table('kpi_ro_targets')
            ->whereDate('period', $periodDate)
            ->get()
            ->keyBy(fn($r) => str_pad(trim((string)$r->ao_code), 6, '0', STR_PAD_LEFT));

        // manual actual map
        $manuals = KpiRoManualActual::query()
            ->whereDate('period', $periodDate)
            ->get()
            ->keyBy(fn($m) => str_pad(trim((string)$m->ao_code), 6, '0', STR_PAD_LEFT));

        return view('kpi.ro.noa_edit', compact('period', 'periodDate', 'ros', 'targets', 'manuals'));
    }

    public function upsert(Request $request)
    {
        Gate::authorize('kpi-ro-noa-manual-edit');

        $data = $request->validate([
            'period' => ['required', 'date'],
            'rows'   => ['required', 'array'],
            'rows.*.ao_code' => ['required', 'string'],
            'rows.*.noa_pengembangan' => ['nullable', 'integer', 'min:0'],
            'rows.*.notes' => ['nullable', 'string'],
        ]);

        $periodDate = Carbon::parse($data['period'])->startOfMonth()->toDateString();
        $me = auth()->user();

        DB::transaction(function () use ($data, $periodDate, $me) {
            foreach ($data['rows'] as $row) {
                $ao = str_pad(trim((string)$row['ao_code']), 6, '0', STR_PAD_LEFT);

                KpiRoManualActual::updateOrCreate(
                    ['period' => $periodDate, 'ao_code' => $ao],
                    [
                        'noa_pengembangan' => (int)($row['noa_pengembangan'] ?? 0),
                        'notes'            => trim((string)($row['notes'] ?? '')) ?: null,
                        'input_by'         => (int)$me->id,
                        'input_at'         => now(),
                    ]
                );
            }
        });

        return back()->with('ok', 'NOA Pengembangan berhasil disimpan.');
    }
}