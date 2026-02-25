<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Kpi\TlroLeadershipBuilder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class TlroLeadershipSheetController extends Controller
{
    public function index(Request $request, TlroLeadershipBuilder $builder)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $userId = $request->query('user') ?? auth()->id();
        $period = $request->query('period') ?? now()->format('Y-m-01');

        $periodDate = \Carbon\Carbon::parse($period)->startOfMonth()->toDateString();

        $user = \App\Models\User::findOrFail($userId);
        
        $role = strtoupper($me->roleValue());
        abort_unless(in_array($role, ['TLRO','KSLR','KBL','ADMIN','SUPERADMIN'], true), 403);

        // =====================================================
        // 1) Period (startOfMonth)
        // =====================================================
        $periodQ = trim((string)$request->query('period', now()->startOfMonth()->toDateString()));
        $period  = Carbon::parse($periodQ)->startOfMonth();

        // =====================================================
        // 2) View-as TLRO (untuk KSLR/KBL/ADMIN)
        // =====================================================
        $tlroIdQ = (int) $request->query('tlro_id', 0);
        $subjectUser = $me;

        if ($role !== 'TLRO' && $tlroIdQ > 0) {

            $subjectUser = User::query()->whereKey($tlroIdQ)->firstOrFail();
            abort_unless(strtoupper($subjectUser->roleValue()) === 'TLRO', 403);

            // KSLR hanya boleh TLRO dalam scope
            if ($role === 'KSLR') {
                $isInScope = DB::table('org_assignments')
                    ->where('leader_id', (int)$me->id)
                    ->where('user_id', (int)$subjectUser->id)
                    ->where('active', 1)
                    ->exists();

                abort_unless($isInScope, 403);
            }

            // (opsional) KBL scope nanti kita bahas belakangan
        }

        // =====================================================
        // 3) Build KPI TLRO (row leadership)
        // =====================================================
        $row = $builder->build((int)$subjectUser->id, $period->toDateString());

        // =====================================================
        // 4) Breakdown RO scope (berdasarkan org_assignments TLRO -> RO)
        // =====================================================
        $roIds = DB::table('org_assignments')
            ->where('leader_id', (int)$subjectUser->id)
            ->pluck('user_id')
            ->toArray();

        $roRows = collect();

        if (!empty($roIds)) {

            // Ambil ao_code RO dari users (scope)
            $aoCodes = DB::table('users')
                ->whereIn('id', $roIds)
                ->whereNotNull('ao_code')
                ->pluck('ao_code')
                ->map(fn($v) => str_pad(trim((string)$v), 6, '0', STR_PAD_LEFT))
                ->filter(fn($v) => $v !== '' && $v !== '000000')
                ->unique()
                ->values()
                ->toArray();

            if (!empty($aoCodes)) {

                $roRows = collect();

                if (!empty($roIds)) {

                    // 1) AO scope TLRO dari table users
                    $aoCodes = DB::table('users')
                        ->whereIn('id', $roIds)
                        ->whereNotNull('ao_code')
                        ->pluck('ao_code')
                        ->map(fn($v) => str_pad(trim((string)$v), 6, '0', STR_PAD_LEFT))
                        ->filter(fn($v) => $v !== '' && $v !== '000000')
                        ->unique()
                        ->values()
                        ->toArray();

                    if (!empty($aoCodes)) {

                        $start = $period->copy()->startOfMonth()->toDateString();
                        $end   = $period->copy()->endOfMonth()->toDateString();

                        // 2) Subquery: tanggal daily terakhir per AO dalam bulan tsb
                        $latestDaily = DB::table('kpi_os_daily_aos')
                            ->selectRaw("LPAD(TRIM(ao_code),6,'0') as ao_code6, MAX(position_date) as max_date")
                            ->whereBetween('position_date', [$start, $end])
                            ->groupBy('ao_code6');

                        // 3) Query KPI RO monthly + join daily OS
                        $roRows = DB::table('kpi_ro_monthly as r')
                            // join user utk ambil nama RO + user_id
                            ->leftJoin('users as u', DB::raw("LPAD(TRIM(u.ao_code),6,'0')"), '=', DB::raw("LPAD(TRIM(r.ao_code),6,'0')"))

                            // join latest daily date per AO
                            ->leftJoinSub($latestDaily, 'ld', function ($j) {
                                $j->on(DB::raw("LPAD(TRIM(r.ao_code),6,'0')"), '=', 'ld.ao_code6');
                            })

                            // join row daily-nya (yang max_date)
                            ->leftJoin('kpi_os_daily_aos as kd', function ($j) {
                                $j->on(DB::raw("LPAD(TRIM(kd.ao_code),6,'0')"), '=', DB::raw("LPAD(TRIM(r.ao_code),6,'0')"))
                                ->on('kd.position_date', '=', 'ld.max_date');
                            })

                            ->whereDate('r.period_month', $row->period)
                            ->where('r.calc_mode', $row->calc_mode)
                            ->whereIn(DB::raw("LPAD(TRIM(r.ao_code),6,'0')"), $aoCodes)

                            ->select([
                                DB::raw("LPAD(TRIM(r.ao_code),6,'0') as ao_code"),
                                'u.id as ro_user_id',
                                'u.name as ro_name',

                                // OS ambil dari daily snapshot terakhir dalam bulan tsb
                                DB::raw("COALESCE(kd.os_total, 0) as os_total"),

                                // RR% dari KPI RO monthly
                                DB::raw("COALESCE(r.repayment_pct, 0) as rr_pct"),

                                // LT% dari daily snapshot (os_lt / os_total)
                                DB::raw("CASE WHEN COALESCE(kd.os_total,0) > 0
                                        THEN (COALESCE(kd.os_lt,0) / kd.os_total) * 100
                                        ELSE 0 END as lt_pct"),

                                // DPK% ambil dari KPI RO monthly (konsisten scoring)
                                DB::raw("COALESCE(r.dpk_pct, 0) as dpk_pct"),

                                // Score
                                DB::raw("COALESCE(r.total_score_weighted, 0) as total_score_weighted"),

                                // Risk belum ada → amanin dulu
                               DB::raw("
                                    CASE
                                        WHEN COALESCE(r.dpk_pct,0) >= 5 OR
                                            (CASE WHEN COALESCE(kd.os_total,0) > 0 THEN (COALESCE(kd.os_lt,0)/kd.os_total)*100 ELSE 0 END) >= 35
                                        THEN 6

                                        WHEN COALESCE(r.dpk_pct,0) >= 3 OR
                                            (CASE WHEN COALESCE(kd.os_total,0) > 0 THEN (COALESCE(kd.os_lt,0)/kd.os_total)*100 ELSE 0 END) >= 30
                                        THEN 5

                                        WHEN COALESCE(r.dpk_pct,0) >= 2 OR
                                            (CASE WHEN COALESCE(kd.os_total,0) > 0 THEN (COALESCE(kd.os_lt,0)/kd.os_total)*100 ELSE 0 END) >= 25
                                        THEN 4

                                        WHEN COALESCE(r.dpk_pct,0) >= 1 OR
                                            (CASE WHEN COALESCE(kd.os_total,0) > 0 THEN (COALESCE(kd.os_lt,0)/kd.os_total)*100 ELSE 0 END) >= 20
                                        THEN 3

                                        WHEN COALESCE(r.dpk_pct,0) >= 0.5 OR
                                            (CASE WHEN COALESCE(kd.os_total,0) > 0 THEN (COALESCE(kd.os_lt,0)/kd.os_total)*100 ELSE 0 END) >= 10
                                        THEN 2

                                        ELSE 1
                                    END
                                    as risk_index
                                    "),

                                // optional debug
                                'ld.max_date as daily_date',
                            ])
                            ->orderByDesc('total_score_weighted')
                            ->get();
                    }
                }
            }
        }

        // =====================================================
        // 5) AI Narrative
        // =====================================================
        [$aiTitle, $aiBullets, $aiActions] = $this->buildAiNarrative($row, $roRows);

        $periodLabel = $period->translatedFormat('F Y');
        $modeLabel   = $row->calc_mode === 'realtime'
            ? 'Realtime (bulan berjalan)'
            : 'Freeze / EOM';

        $fmt2 = fn($v) => number_format((float)($v ?? 0), 2, ',', '.');

        return view('kpi.tlro.sheet', [
            'viewerUser'  => $me,
            'subjectUser' => $subjectUser,
            'period'      => $period->toDateString(),
            'periodLabel' => $periodLabel,
            'modeLabel'   => $modeLabel,
            'row'         => $row,
            'roRows'      => $roRows,
            'aiTitle'     => $aiTitle,
            'aiBullets'   => $aiBullets,
            'aiActions'   => $aiActions,
            'fmt2'        => $fmt2,

            // supaya tombol recalc bisa tetap view-as
            'tlroIdQ'     => $tlroIdQ,
        ]);
    }

    public function recalc(Request $request, TlroLeadershipBuilder $builder)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $role = strtoupper($me->roleValue());
        abort_unless(in_array($role, ['TLRO','KSLR','KBL','ADMIN','SUPERADMIN'], true), 403);

        $periodQ = trim((string)$request->input('period', now()->startOfMonth()->toDateString()));
        $period  = Carbon::parse($periodQ)->startOfMonth();

        // view-as (optional)
        $tlroIdQ = (int) $request->input('tlro_id', 0);
        $subjectUser = $me;

        if ($role !== 'TLRO' && $tlroIdQ > 0) {

            $subjectUser = User::query()->whereKey($tlroIdQ)->firstOrFail();
            abort_unless(strtoupper($subjectUser->roleValue()) === 'TLRO', 403);

            if ($role === 'KSLR') {
                $isInScope = DB::table('org_assignments')
                    ->where('leader_id', (int)$me->id)
                    ->where('user_id', (int)$subjectUser->id)
                    ->where('active', 1)
                    ->exists();

                abort_unless($isInScope, 403);
            }
        }

        $builder->build((int)$subjectUser->id, $period->toDateString());

        return redirect()
            ->route('kpi.tlro.sheet', [
                'period'  => $period->toDateString(),
                'tlro_id' => $tlroIdQ ?: null,
            ])
            ->with('success', 'TLRO Leadership Index berhasil direcalc.');
    }

    // =========================================================
    // AI Narrative Engine
    // =========================================================
    private function buildAiNarrative($row, $roRows): array
    {
        $li   = (float)($row->leadership_index ?? 0);
        $pi   = (float)($row->pi_scope ?? 0);
        $stab = (float)($row->stability_index ?? 0);
        $risk = (float)($row->risk_index ?? 0);
        $imp  = (float)($row->improvement_index ?? 0);

        $status = strtoupper((string)($row->status_label ?? ''));

        $title = match (true) {
            $status === 'AMAN'   => 'Tim RO stabil dan terkendali. Pertahankan disiplin kolektibilitas.',
            $status === 'WASPADA'=> 'Perlu penguatan monitoring migrasi LT/DPK.',
            $status === 'KRITIS' => 'Risiko kolektibilitas meningkat. Intervensi bottom RO segera.',
            default              => 'Ringkasan kepemimpinan TLRO.',
        };

        $bullets = [
            "Leadership Index: {$li} (Status: " . ($status ?: 'N/A') . ")",
            "PI_scope (avg RO): {$pi}",
            "Stability: {$stab} · Risk: {$risk} · Improvement: {$imp}",
        ];

        if ($roRows instanceof \Illuminate\Support\Collection && $roRows->count() > 0) {
            $sorted = $roRows->sortByDesc(fn($r) => (float)($r->total_score_weighted ?? 0))->values();
            $top = $sorted->first();
            $bot = $sorted->last();

            $bullets[] = "Top RO: {$top->ro_name} (Score " . (float)$top->total_score_weighted . ")";
            if ($sorted->count() > 1) {
                $bullets[] = "Bottom RO: {$bot->ro_name} (Score " . (float)$bot->total_score_weighted . ")";
            }
        } else {
            $bullets[] = "Belum ada data RO scope atau KPI RO belum terbentuk pada periode ini.";
        }

        $actions = [];

        if ($roRows instanceof \Illuminate\Support\Collection && $roRows->count() > 1) {
            $sorted = $roRows->sortBy(fn($r) => (float)($r->total_score_weighted ?? 0))->values();
            $bot = $sorted->first();
            $actions[] = "Coaching RO terbawah: {$bot->ro_name} — review pipeline & kolektibilitas.";
        } elseif ($roRows instanceof \Illuminate\Support\Collection && $roRows->count() === 1) {
            $only = $roRows->first();
            $actions[] = "Scope baru 1 RO: pastikan disiplin monitoring mingguan (LT/DPK/Repayment). RO: {$only->ro_name}.";
        } else {
            $actions[] = "Pastikan org_assignments TLRO→RO terisi & KPI RO sudah direcalc untuk periode ini.";
        }

        if ($risk > 0 && $risk < 3) $actions[] = "Kontrol migrasi LT & DPK (weekly monitoring).";
        if ($imp > 0 && $imp < 3) $actions[] = "Improvement lemah: buat target mingguan per RO & early warning system.";
        if ($stab > 0 && $stab < 3) $actions[] = "Stability rendah: ratakan performa RO via coaching dan standardisasi eksekusi.";

        return [$title, $bullets, array_slice($actions, 0, 5)];
    }
}