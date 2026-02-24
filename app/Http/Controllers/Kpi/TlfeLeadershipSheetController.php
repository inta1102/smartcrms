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

        // ==== View-as TLFE (from KSFE breakdown click) ====
        $tlfeIdQ = (int) $request->query('tlfe_id', 0);
        $subjectUser = null;

        // Case A) TLFE login => selalu lihat dirinya sendiri, abaikan tlfe_id (biar aman)
        if ($role === 'TLFE') {
            $subjectUser = $me;
        } else {
            // Case B) role leader/admin => boleh view TLFE tertentu, tapi harus ada tlfe_id
            abort_unless($tlfeIdQ > 0, 404); // atau 403 kalau kamu mau strict

            $subjectUser = \App\Models\User::query()
                ->whereKey($tlfeIdQ)
                ->firstOrFail();

            abort_unless(strtoupper($subjectUser->roleValue()) === 'TLFE', 403);

            // KSFE: TLFE harus termasuk scope
            if ($role === 'KSFE') {
                $isInScope = DB::table('org_assignments')
                    ->where('leader_id', (int)$me->id)
                    ->where('user_id', (int)$subjectUser->id)
                    ->exists();

                abort_unless($isInScope, 403);
            }

            // KBL rules: skip dulu (sesuai keputusan kamu)
            // ADMIN/SUPERADMIN: bebas
        }

        // =====================================================
        // 1) SOURCE OF TRUTH: FE pack (YTD) - pakai subject TLFE
        // =====================================================
        $pack = $feSvc->buildForPeriod($period->format('Y-m'), $subjectUser);

        $items    = collect($pack['items'] ?? []);
        $tlRecap  = $pack['tlFeRecap'] ?? null;

        // start YTD (display)
        $startYtd = $pack['startYtd'] ?? $period->copy()->startOfYear()->toDateString();

        // =====================================================
        // endYtd / asOfDate (display):
        // - past month => endOfMonth(period)
        // - current month => last position date (loan_accounts / kpi_os_daily_aos) capped <= endOfMonth(period)
        // =====================================================
        $isCurrentMonth = $period->equalTo(now()->startOfMonth());

        // default end (EOM of selected period)
        $monthEnd = $period->copy()->endOfMonth()->toDateString();
        $endYtd   = $pack['endYtd'] ?? $monthEnd; // kalau service sudah ngasih, pakai itu dulu

        if ($isCurrentMonth) {
            $latest = null;

            if (Schema::hasTable('loan_accounts') && Schema::hasColumn('loan_accounts', 'position_date')) {
                $latest = DB::table('loan_accounts')->max('position_date');
            }

            if (!$latest && Schema::hasTable('kpi_os_daily_aos') && Schema::hasColumn('kpi_os_daily_aos', 'position_date')) {
                $latest = DB::table('kpi_os_daily_aos')->max('position_date');
            }

            if ($latest) {
                $latestDate = Carbon::parse($latest)->toDateString();
                // guard: jangan lewat dari akhir bulan period yang dipilih
                $endYtd = min($latestDate, $monthEnd);
            } else {
                $endYtd = $monthEnd;
            }
        } else {
            // bulan lampau: endYtd harus endOfMonth(period)
            $endYtd = $monthEnd;
        }

        // =====================================================
        // 2) Leadership row (LI/meta)
        // =====================================================
        $row = $builder->build((int)$subjectUser->id, $period->toDateString());

        $scopeCount = $tlRecap?->scope_count ?? $items->count();

        [$aiTitle, $aiBullets, $aiActions] = $this->buildAiNarrative($row, $items);

        $fmt2 = fn($v) => number_format((float)($v ?? 0), 2, ',', '.');

        $periodLabel = $period->translatedFormat('F Y');
        $modeLabel   = (($pack['mode'] ?? 'eom') === 'realtime') ? 'Realtime (bulan berjalan)' : 'Freeze / EOM';

        return view('kpi.tlfe.sheet', [
            'me'          => $me,
            'subjectUser' => $subjectUser,
            'viewerUser'  => $me,
            'period'      => $period->toDateString(),
            'periodLabel' => $periodLabel,
            'modeLabel'   => $modeLabel,

            'row'         => $row,

            'feRows'      => $items,
            'tlRecap'     => $tlRecap,

            // ✅ ini yang dipakai chip "Akumulasi ..."
            'startYtd'    => $startYtd,
            'endYtd'      => $endYtd,

            'aiTitle'     => $aiTitle,
            'aiBullets'   => $aiBullets,
            'aiActions'   => $aiActions,
            'fmt2'        => $fmt2,

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