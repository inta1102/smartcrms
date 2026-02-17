<?php

namespace App\Http\Controllers\Kpi;

use App\Models\KpiRoMonthly;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Illuminate\Support\Facades\Gate;


class KpiRoController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        abort_unless($u && !empty($u->ao_code), 403);

        $ao = str_pad(trim((string)$u->ao_code), 6, '0', STR_PAD_LEFT);

        $period = $request->get('period')
            ? Carbon::parse($request->get('period'))->startOfMonth()
            : now()->startOfMonth();

        $periodMonth = $period->toDateString();
        $prevMonth   = $period->copy()->subMonth()->startOfMonth()->toDateString();

        // realtime bulan ini
        $rt = KpiRoMonthly::query()
            ->whereDate('period_month', $periodMonth)
            ->where('ao_code', $ao)
            ->where('calc_mode', 'realtime')
            ->orderByDesc('updated_at')
            ->first();

        // EOM bulan lalu (locked)
        $eomPrev = KpiRoMonthly::query()
            ->whereDate('period_month', $prevMonth)
            ->where('ao_code', $ao)
            ->where('calc_mode', 'eom')
            ->whereNotNull('locked_at')
            ->orderByDesc('locked_at')
            ->first();

        // fallback: kalau belum ada eom, tampilkan realtime bulan lalu (biar RO tetap lihat historis)
        $rtPrev = null;
        if (!$eomPrev) {
            $rtPrev = KpiRoMonthly::query()
                ->whereDate('period_month', $prevMonth)
                ->where('ao_code', $ao)
                ->where('calc_mode', 'realtime')
                ->orderByDesc('updated_at')
                ->first();
        }

        return view('kpi.ro.index', compact('ao', 'periodMonth', 'prevMonth', 'rt', 'eomPrev', 'rtPrev'));
    }

    /**
     * RO Sheet (detail per RO)
     * URL: /kpi/ro/{user}?period=YYYY-MM atau YYYY-MM-01
     */
    public function show(Request $request, int $id)
    {
       $user = User::findOrFail($id);

        // ✅ pakai Gate KPI RO, bukan UserPolicy
        Gate::authorize('kpi-ro-view', $user);
        
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

        // KPI RO: tabel kpi_ro_monthly pakai ao_code + period_month
        $kpi = DB::table('kpi_ro_monthly')
            ->where('ao_code', (string)($user->ao_code ?? ''))
            ->whereDate('period_month', $periodYmd)
            ->first();

        $aoCode = str_pad(trim((string)($user->ao_code ?? '')), 6, '0', STR_PAD_LEFT);

        $target = DB::table('kpi_ro_targets')
            ->where('ao_code', $aoCode)
            ->whereDate('period', $periodYmd)
            ->first();


        // ====== Enterprise variables for the view (biar nggak error berantai) ======
        $top3 = [];
        if (!empty($kpi?->topup_top3_json)) {
            $decoded = json_decode($kpi->topup_top3_json, true);
            if (is_array($decoded)) $top3 = $decoded;
        }

        // Thresholds (mudah diedit di 1 tempat)
        $thr = $this->thresholds();

        // RR & DPK
        $rrPct  = (float)($kpi?->repayment_pct ?? 0); // 0..100
        $dpkPct = (float)($kpi?->dpk_pct ?? 0);       // 0..100

        $rrBadge  = $this->badgeRR($rrPct, $thr);
        $dpkBadge = $this->badgeDPK($dpkPct, $thr);

        $interpretasi = $this->buildInterpretation($kpi, $rrBadge, $dpkBadge);

        return view('kpi.ro.show', [
            'user'         => $user,
            'kpi'          => $kpi,
            'target'       => $target,
            'periodYmd'    => $periodYmd,
            'periodLabel'  => $periodLabel,

            // ✅ ini yang kemarin belum kamu kirim -> bikin undefined
            'top3'         => $top3,
            'thr'          => $thr,
            'rrPct'        => $rrPct,
            'dpkPct'       => $dpkPct,
            'rrBadge'      => $rrBadge,
            'dpkBadge'     => $dpkBadge,
            'interpretasi' => $interpretasi,
        ]);
    }

    private function resolvePeriodYmd(Request $request): string
    {
        $raw = trim((string)$request->query('period', ''));

        if ($raw === '') {
            return now()->startOfMonth()->toDateString();
        }

        // support YYYY-MM (month picker)
        if (preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return Carbon::createFromFormat('Y-m', $raw)->startOfMonth()->toDateString();
        }

        // support YYYY-MM-DD
        try {
            return Carbon::parse($raw)->startOfMonth()->toDateString();
        } catch (\Throwable $e) {
            return now()->startOfMonth()->toDateString();
        }
    }

    /**
     * Threshold mudah diedit (nanti kalau kamu mau enterprise beneran, kita pindah ke config)
     */
    private function thresholds(): array
    {
        return [
            // RR badge pakai repayment_pct (0..100)
            'rr' => [
                'aman_min'    => 90.0,
                'waspada_min' => 80.0,
            ],
            // DPK risk (semakin kecil semakin bagus)
            'dpk' => [
                'aman_max'    => 1.0,
                'waspada_max' => 3.0,
            ],
        ];
    }

    private function badgeRR(float $rrPct, array $thr): array
    {
        $amanMin = (float)($thr['rr']['aman_min'] ?? 90);
        $waspadaMin = (float)($thr['rr']['waspada_min'] ?? 80);

        if ($rrPct >= $amanMin) {
            return ['label' => 'AMAN', 'cls' => 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200'];
        }
        if ($rrPct >= $waspadaMin) {
            return ['label' => 'WASPADA', 'cls' => 'bg-yellow-100 text-yellow-700 ring-1 ring-yellow-200'];
        }
        return ['label' => 'RISIKO', 'cls' => 'bg-rose-100 text-rose-700 ring-1 ring-rose-200'];
    }

    private function badgeDPK(float $dpkPct, array $thr): array
    {
        $amanMax = (float)($thr['dpk']['aman_max'] ?? 1.0);
        $waspadaMax = (float)($thr['dpk']['waspada_max'] ?? 3.0);

        if ($dpkPct <= $amanMax) {
            return ['label' => 'AMAN', 'cls' => 'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200'];
        }
        if ($dpkPct <= $waspadaMax) {
            return ['label' => 'WASPADA', 'cls' => 'bg-yellow-100 text-yellow-700 ring-1 ring-yellow-200'];
        }
        return ['label' => 'RISIKO', 'cls' => 'bg-rose-100 text-rose-700 ring-1 ring-rose-200'];
    }

    private function buildInterpretation($kpi, array $rrBadge, array $dpkBadge): string
    {
        if (!$kpi) return 'Data KPI belum tersedia untuk periode ini — jalankan proses kalkulasi/rebuild KPI.';

        $parts = [];

        // RR insight
        if (($rrBadge['label'] ?? '') === 'AMAN') {
            $parts[] = 'RR masuk AMAN — pertahankan disiplin follow-up dan kualitas bayar.';
        } elseif (($rrBadge['label'] ?? '') === 'WASPADA') {
            $parts[] = 'RR masuk WASPADA — tingkatkan intensitas follow-up untuk mencegah penurunan kolektibilitas.';
        } else {
            $parts[] = 'RR masuk RISIKO — fokus perbaikan kolektibilitas dan percepat tindakan penagihan/mitigasi.';
        }

        // DPK insight
        if (($dpkBadge['label'] ?? '') === 'RISIKO') {
            $parts[] = 'DPK relatif tinggi — prioritas restruktur/penanganan akun yang mulai memburuk.';
        }

        // Growth insight (topup/noa)
        $topupPct = (float)($kpi->topup_pct ?? 0);
        if ($topupPct >= 100) {
            $parts[] = 'Topup di atas target — pastikan konsentrasi topup tidak terlalu tinggi pada sedikit CIF.';
        }

        return implode(' ', $parts);
    }
}
