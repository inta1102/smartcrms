<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class KpiSoController extends Controller
{
    public function show(Request $request, int $id)
    {
        $user = User::findOrFail($id);

        // ✅ authorize KPI SO, bukan UserPolicy
        Gate::authorize('kpi-so-view', $user);

        // period: support 2026-02 / 2026-02-01
        $raw = trim((string)$request->query('period', ''));
        if ($raw === '') {
            $periodYmd = now()->startOfMonth()->toDateString();
        } elseif (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            $periodYmd = Carbon::createFromFormat('Y-m', $raw)->startOfMonth()->toDateString();
        } else {
            $periodYmd = Carbon::parse($raw)->startOfMonth()->toDateString();
        }

        $periodLabel = Carbon::parse($periodYmd)->translatedFormat('F Y');

        // KPI SO monthly
        $kpi = DB::table('kpi_so_monthlies')
            ->where('user_id', (int)$user->id)
            ->whereDate('period', $periodYmd)
            ->first();

        // Target SO
        $target = DB::table('kpi_so_targets')
            ->where('user_id', (int)$user->id)
            ->whereDate('period', $periodYmd)
            ->first();

        // helpers
        $fmtRp = fn($n) => 'Rp ' . number_format((int)($n ?? 0), 0, ',', '.');
        $fmtPct = function ($n, $dec=2) {
            $v = (float)($n ?? 0);
            return number_format($v, $dec, ',', '.') . '%';
        };
        $fmt2 = fn($n) => number_format((float)($n ?? 0), 2, ',', '.');

        // derive % pencapaian (safe)
        $osTarget  = (float)($target->target_os_disbursement ?? 0);
        $noaTarget = (float)($target->target_noa_disbursement ?? 0);
        $rrTarget  = (float)($target->target_rr ?? 100);
        $actTarget = (float)($target->target_activity ?? 0);

        $osAct  = (float)($kpi->os_disbursement ?? 0);
        $noaAct = (float)($kpi->noa_disbursement ?? 0);
        $rrAct  = (float)($kpi->rr_pct ?? 0);
        $actAct = (float)($kpi->activity_actual ?? 0);

        $osPct  = $osTarget > 0 ? ($osAct / $osTarget) * 100 : 0;
        $noaPct = $noaTarget > 0 ? ($noaAct / $noaTarget) * 100 : 0;
        $rrPct  = $rrTarget > 0 ? ($rrAct / $rrTarget) * 100 : 0;
        $actPct = $actTarget > 0 ? ($actAct / $actTarget) * 100 : 0;

        // interpretasi singkat (boleh kamu refine)
        $interpretasi = [];
        if (!$kpi) {
            $interpretasi[] = "Belum ada data KPI SO pada periode ini.";
        } else {
            if ($rrAct >= 100) $interpretasi[] = "RR on track (≥ target). Pertahankan disiplin pembayaran.";
            elseif ($rrAct >= 90) $interpretasi[] = "RR sedikit di bawah target. Perkuat kontrol kualitas debitur.";
            else $interpretasi[] = "RR rendah. Prioritaskan follow-up kolektif & early warning.";

            if ($osAct > 0 && $osPct >= 100) $interpretasi[] = "OS disbursement sudah mencapai target.";
            elseif ($osAct > 0) $interpretasi[] = "OS disbursement masih di bawah target. Dorong pipeline & closing.";
            else $interpretasi[] = "Belum ada OS disbursement. Cek pipeline / input data / mapping.";
        }

        return view('kpi.so.show', [
            'user'        => $user,
            'kpi'         => $kpi,
            'target'      => $target,
            'periodYmd'   => $periodYmd,
            'periodLabel' => $periodLabel,

            // helpers + derived
            'fmtRp' => $fmtRp,
            'fmtPct'=> $fmtPct,
            'fmt2'  => $fmt2,

            'osPct'  => $osPct,
            'noaPct' => $noaPct,
            'rrAch'  => $rrPct,
            'actPct' => $actPct,
            'interpretasi' => $interpretasi,
        ]);
    }
}
