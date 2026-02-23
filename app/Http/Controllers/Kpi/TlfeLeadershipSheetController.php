<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use App\Services\Kpi\TlfeLeadershipBuilder;
use App\Services\Kpi\FeKpiMonthlyService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TlfeLeadershipSheetController extends Controller
{
    public function index(Request $request, TlfeLeadershipBuilder $builder, FeKpiMonthlyService $feSvc)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $role = strtoupper($me->roleValue());
        abort_unless(in_array($role, ['TLFE','KSFE','KBL','ADMIN','SUPERADMIN'], true), 403);

        // ==== Period (startOfMonth) ====
        $periodQ = trim((string)$request->query('period', now()->startOfMonth()->toDateString()));
        $period  = Carbon::parse($periodQ)->startOfMonth();

        /**
         * =====================================================
         * 1) Build YTD FE items + TL recap (SOURCE OF TRUTH)
         *    - ini yang kemarin sudah benar
         * =====================================================
         */
        $pack = $feSvc->buildForPeriod($period->format('Y-m'), $me);

        // items = FE KPI YTD (per FE)
        $items   = collect($pack['items'] ?? []);
        $tlRecap = $pack['tlFeRecap'] ?? null;
        $startYtd = $pack['startYtd'] ?? $period->copy()->startOfYear()->toDateString();

        // =====================================================
        // endYtd:
        // - kalau bulan berjalan => pakai tanggal terakhir data (loan_accounts / fallback)
        // - kalau bulan lampau => endOfMonth
        // =====================================================
        $isCurrentMonth = $period->equalTo(now()->startOfMonth());

        $endYtd = $pack['endYtd'] ?? $period->copy()->endOfMonth()->toDateString();

        if ($isCurrentMonth) {
            $latest = null;

            // 1) kalau loan_accounts punya kolom position_date
            if (Schema::hasColumn('loan_accounts', 'position_date')) {
                $latest = DB::table('loan_accounts')->max('position_date');
            }

            // 2) fallback: kalau ada kpi_os_daily_aos (biasanya paling update)
            if (!$latest && Schema::hasTable('kpi_os_daily_aos') && Schema::hasColumn('kpi_os_daily_aos', 'position_date')) {
                $latest = DB::table('kpi_os_daily_aos')->max('position_date');
            }

            // 3) fallback terakhir: end of month
            if ($latest) {
                $endYtd = Carbon::parse($latest)->toDateString();
            }
        }

        /**
         * =====================================================
         * 2) Leadership row (optional)
         *    - builder boleh tetap dipakai buat LI/narrative/meta,
         *      tapi ANGKA KPI utamanya tetap dari $tlRecap
         * =====================================================
         */
        $row = $builder->build((int)$me->id, $period->toDateString());

        // ==== FE Scope count (pakai tlRecap jika ada, biar konsisten) ====
        $scopeCount = $tlRecap?->scope_count ?? $items->count();

        // ==== AI Narrative (boleh tetap dari $row + $items) ====
        [$aiTitle, $aiBullets, $aiActions] = $this->buildAiNarrative($row, $items);

        $fmt2 = fn($v) => number_format((float)($v ?? 0), 2, ',', '.');

        $periodLabel = $period->translatedFormat('F Y');
        $modeLabel   = (($pack['mode'] ?? 'eom') === 'realtime') ? 'Realtime (bulan berjalan)' : 'Freeze / EOM';

        /**
         * =====================================================
         * 3) IMPORTANT:
         *    - untuk reuse sheet_fe: kirim 'items' sebagai FE rows
         *    - kirim tlRecap dari service (YTD TL aggregate)
         * =====================================================
         */
        return view('kpi.tlfe.sheet', [
            'me'          => $me,
            'period'      => $period->toDateString(),
            'periodLabel' => $periodLabel,
            'modeLabel'   => $modeLabel,

            // row leadership (LI, meta)
            'row'         => $row,

            // ✅ FE breakdown YTD (ini yang ditampilkan di ranking FE scope)
            'feRows'      => $items,

            // ✅ TL recap YTD (ini yang harus dipakai header angka TL)
            'tlRecap'     => $tlRecap,

            // ✅ window YTD bener
            'startYtd'    => $startYtd,
            'endYtd'      => $endYtd,

            'aiTitle'     => $aiTitle,
            'aiBullets'   => $aiBullets,
            'aiActions'   => $aiActions,
            'fmt2'        => $fmt2,

            // bonus tampilan
            'scopeCount'  => $scopeCount,
        ]);
    }

    private function buildAiNarrative($row, $items): array
    {
        $li = (float)($row->leadership_index ?? 0);
        $spread = $row->meta['spread'] ?? null;
        $bottom = $row->meta['bottom'] ?? null;
        $coverage = $row->meta['coverage_pct'] ?? null;

        $title = match(true) {
            $li >= 4.5 => 'Tim FE stabil dan sehat.',
            $li >= 3.5 => 'Performa cukup baik, perlu konsistensi.',
            $li >= 2.5 => 'Mulai ada ketidakseimbangan performa.',
            default => 'Tim dalam kondisi berisiko. Perlu intervensi segera.'
        };

        $bullets = [];
        $bullets[] = "Leadership Index: {$li}";
        if($spread !== null) $bullets[] = "Spread antar FE: " . round($spread,2);
        if($coverage !== null) $bullets[] = "Coverage FE >= 3: " . round($coverage,2) . "%";
        if($bottom !== null) $bullets[] = "Bottom FE PI: " . round($bottom,2);

        $actions = [];
        if($items instanceof \Illuminate\Support\Collection && $items->count() > 0) {
            $ranked = $items->sortByDesc(fn($it) => (float)($it->pi_total ?? 0))->values();
            $top = $ranked->first();
            $bot = $ranked->last();

            $actions[] = "Coaching FE terbawah: " . ($bot->name ?? '-');
            $actions[] = "Evaluasi strategi FE terbaik: " . ($top->name ?? '-');
        }

        if($spread !== null && $spread > 2) $actions[] = "Spread tinggi → lakukan weekly monitoring 1-on-1.";
        if(($row->risk_index ?? 0) < 3) $actions[] = "Risk rendah → kontrol migrasi NPL FE.";

        return [$title, $bullets, $actions];
    }


    public function recalc(Request $request, TlfeLeadershipBuilder $builder)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        $periodQ = trim((string)$request->input('period', now()->startOfMonth()->toDateString()));
        $period  = Carbon::parse($periodQ)->startOfMonth();

        $builder->build((int)$me->id, $period->toDateString());

        return redirect()
            ->route('kpi.tlfe.sheet', ['period' => $period->toDateString()])
            ->with('success', 'TLFE Leadership berhasil direcalc.');
    }

    /*
    |--------------------------------------------------------------------------
    | AI Narrative TLFE
    |--------------------------------------------------------------------------
    */
 
}