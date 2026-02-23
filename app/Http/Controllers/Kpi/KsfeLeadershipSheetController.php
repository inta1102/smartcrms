<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Models\KpiKsfeMonthly;
use App\Services\Kpi\KsfeLeadershipBuilder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KsfeLeadershipSheetController extends Controller
{
    public function index(Request $request, KsfeLeadershipBuilder $builder)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        // ===== Guard role (sesuaikan sesuai sistem role kamu) =====
        // Opsi 1: ada kolom role string: $me->role
        // Opsi 2: ada method hasRole()
        $role = strtoupper((string)($me->role ?? ''));
        $can = in_array($role, ['KSFE', 'KBL', 'ADMIN', 'SUPERADMIN'], true)
            || (method_exists($me, 'hasRole') && ($me->hasRole('KSFE') || $me->hasRole('KBL') || $me->hasRole('ADMIN')));

        abort_unless($can, 403);

        // ===== Period (default: bulan ini) =====
        $periodQ = trim((string)$request->query('period', now()->startOfMonth()->toDateString()));
        $period  = Carbon::parse($periodQ)->startOfMonth();

        // ===== Auto-build (optional): build saat page dibuka supaya selalu ada data =====
        // Kalau kamu mau "hemat", kamu bisa matiin dan hanya pakai tombol recalc.
        $row = $builder->build((int)$me->id, $period->toDateString());

        // ===== Ambil list TLFE scope untuk breakdown =====
        $tlfeIds = DB::table('org_assignments')
            ->where('leader_id', (int)$me->id)
            ->where('active', 1)
            ->pluck('user_id')
            ->toArray();

        $tlfeRows = collect();
        if (!empty($tlfeIds)) {
            // Ambil KPI TLFE untuk period & mode yang sama
            $tlfeRows = DB::table('kpi_tlfe_monthlies as t')
                ->leftJoin('users as u', 'u.id', '=', 't.tlfe_id')
                ->whereDate('t.period', $row->period)
                ->where('t.calc_mode', $row->calc_mode)
                ->whereIn('t.tlfe_id', $tlfeIds)
                ->select([
                    't.tlfe_id',
                    'u.name as tlfe_name',
                    't.fe_count',
                    't.pi_scope',
                    't.stability_index',
                    't.risk_index',
                    't.improvement_index',
                    't.leadership_index',
                    't.status_label',
                    't.meta',
                ])
                ->orderByDesc('t.leadership_index')
                ->get()
                ->map(function ($r) {
                    // meta json bisa string (kalau driver belum auto cast)
                    if (is_string($r->meta)) {
                        $decoded = json_decode($r->meta, true);
                        $r->meta = is_array($decoded) ? $decoded : null;
                    }
                    return $r;
                });
        }

        // ===== Label period =====
        $periodLabel = $period->translatedFormat('F Y'); // pastikan locale id sudah diset
        $modeLabel = $row->calc_mode === 'realtime'
            ? 'Realtime (bulan berjalan)'
            : 'Freeze / EOM';

        // ===== Simple AI narrative (copy KSBE style: singkat + actionable) =====
        [$aiTitle, $aiBullets, $aiActions] = $this->buildAiNarrative($row, $tlfeRows);

        // ===== Helpers formatting =====
        $fmt2 = fn($v) => number_format((float)($v ?? 0), 2, ',', '.');

        return view('kpi.ksfe.sheet', [
            'me'          => $me,
            'period'      => $period->toDateString(),
            'periodLabel' => $periodLabel,
            'modeLabel'   => $modeLabel,
            'row'         => $row,
            'tlfeRows'    => $tlfeRows,
            'aiTitle'     => $aiTitle,
            'aiBullets'   => $aiBullets,
            'aiActions'   => $aiActions,
            'fmt2'        => $fmt2,
        ]);
    }

    public function recalc(Request $request, KsfeLeadershipBuilder $builder)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $periodQ = trim((string)$request->input('period', now()->startOfMonth()->toDateString()));
        $period  = Carbon::parse($periodQ)->startOfMonth();

        $builder->build((int)$me->id, $period->toDateString());

        return redirect()
            ->route('kpi.ksfe.sheet', ['period' => $period->toDateString()])
            ->with('success', 'KSFE Leadership Index berhasil direcalc.');
    }

    private function buildAiNarrative($row, $tlfeRows): array
    {
        $li   = (float)($row->leadership_index ?? 0);
        $pi   = (float)($row->pi_scope ?? 0);
        $stab = (float)($row->stability_index ?? 0);
        $risk = (float)($row->risk_index ?? 0);
        $imp  = (float)($row->improvement_index ?? 0);

        $status = strtoupper((string)($row->status_label ?? ''));
        $title = match (true) {
            $status === 'AMAN'   => 'Tim berada dalam kondisi sehat. Fokus berikutnya: konsistensi & scaling.',
            $status === 'WASPADA'=> 'Ada sinyal ketidakstabilan. Butuh intervensi coaching & kontrol risiko.',
            $status === 'KRITIS' => 'Kondisi kritis. Prioritaskan perbaikan bottom performer & governance risiko.',
            default              => 'Ringkasan kepemimpinan KSFE berdasarkan agregasi TLFE.',
        };

        $bullets = [];
        $bullets[] = "Leadership Index: {$li} (Status: " . ($status ?: 'N/A') . ")";
        $bullets[] = "PI_scope (avg LI TLFE): {$pi}";
        $bullets[] = "Stability: {$stab} · Risk: {$risk} · Improvement: {$imp}";

        // Insight TLFE distribution
        if ($tlfeRows && $tlfeRows->count() > 0) {
            $top = $tlfeRows->first();
            $bot = $tlfeRows->last();

            $bullets[] = "Top TLFE: " . ($top->tlfe_name ?: ('#'.$top->tlfe_id)) . " (LI " . (float)$top->leadership_index . ")";
            if ($tlfeRows->count() > 1) {
                $bullets[] = "Bottom TLFE: " . ($bot->tlfe_name ?: ('#'.$bot->tlfe_id)) . " (LI " . (float)$bot->leadership_index . ")";
            } else {
                // khusus: cuma 1 TLFE
                $meta = is_array($row->meta) ? $row->meta : (is_string($row->meta) ? json_decode($row->meta, true) : null);
                if (($meta['stability_note'] ?? null) === 'insufficient_sample') {
                    $bullets[] = "Catatan: TLFE baru 1 orang, stability antar TLFE dinilai netral (tidak dibiasakan 'sempurna').";
                }
            }
        } else {
            $bullets[] = "Belum ada data TLFE di scope atau KPI TLFE belum terbentuk pada periode ini.";
        }

        // Actions Now
        $actions = [];

        if ($tlfeRows && $tlfeRows->count() >= 2) {
            $bottom = $tlfeRows->last();
            $actions[] = "Coaching TLFE terbawah: " . ($bottom->tlfe_name ?: ('#'.$bottom->tlfe_id)) . " — lakukan review pipeline + root cause KPI FE.";
        } elseif ($tlfeRows && $tlfeRows->count() === 1) {
            $only = $tlfeRows->first();
            $actions[] = "Karena baru 1 TLFE, fokus KSFE: scaling SOP & monitoring kualitas KPI FE agar konsisten lintas FE.";
            $actions[] = "Minta TLFE (" . ($only->tlfe_name ?: ('#'.$only->tlfe_id)) . ") bikin weekly review: migrasi NPL + denda + nett OS kol2.";
        } else {
            $actions[] = "Pastikan org_assignments KSFE→TLFE sudah terisi & KPI TLFE sudah direcalc untuk period ini.";
        }

        // Trigger-based
        if ($risk > 0 && $risk < 3.0) {
            $actions[] = "Risk rendah: lakukan kontrol kualitas origination (cek tren migrasi NPL & disiplin kolek awal).";
        }
        if ($imp > 0 && $imp < 3.0) {
            $actions[] = "Improvement lemah: buat target mingguan per TLFE dan monitoring harian (early warning).";
        }
        if ($stab > 0 && $stab < 3.0) {
            $actions[] = "Stability rendah: ratakan performa FE via coaching bottom FE dan standardisasi eksekusi.";
        }

        // max 5 actions biar tidak kepanjangan
        $actions = array_slice($actions, 0, 5);

        return [$title, $bullets, $actions];
    }
}