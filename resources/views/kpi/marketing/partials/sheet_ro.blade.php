<div class="space-y-6">
{{-- ===========================
   TL / LEADER RECAP (ROBUST)
   - support: $tlRecap object OR array/collection
   - detail RR & DPK migrasi: fallback sum dari members jika tersedia
   =========================== --}}

@if(!empty($tlRecap))
@php
  $lr = strtoupper($tlRecap->leader_role ?? 'LEADER');

  // ==== Ambil daftar RO yang sedang ditampilkan (fallback data sumber) ====
  // pakai yang ada di view kamu: $items / $rows / $roItems
  $roList = collect();
  if (isset($items))   $roList = collect($items);
  elseif (isset($rows)) $roList = collect($rows);
  elseif (isset($roItems)) $roList = collect($roItems);

  // pastikan hanya RO bawahan TL yang relevan (kalau list kamu campur TL lain)
  // kalau di item ada leader_id atau tl_id, filter di sini. Kalau tidak ada, biarkan.
  // contoh opsional:
  // $roList = $roList->where('leader_id', $tlRecap->id);

  // ==== Helpers ====
  $kpiBadgeNormal = function ($ach) {
      $ach = (float)($ach ?? 0);
      if ($ach >= 100) return 'bg-emerald-100 text-emerald-800 border border-emerald-200';
      if ($ach >= 80)  return 'bg-amber-100 text-amber-800 border border-amber-200';
      return 'bg-rose-100 text-rose-800 border border-rose-200';
  };

  $kpiBadgeReverse = function ($actual, $target) {
      $actual = (float)($actual ?? 0);
      $target = (float)($target ?? 0);
      if ($target <= 0) return 'bg-slate-100 text-slate-600 border border-slate-200';

      $ratio = ($actual / $target) * 100.0; // <=100 bagus (reverse)
      if ($ratio <= 100) return 'bg-emerald-100 text-emerald-800 border border-emerald-200';
      if ($ratio <= 120) return 'bg-amber-100 text-amber-800 border border-amber-200';
      return 'bg-rose-100 text-rose-800 border border-rose-200';
  };

  $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n) => number_format((float)($n ?? 0), 2) . '%';

  $chip = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200';

  $decodeJsonArr = function ($val) {
      if (empty($val)) return [];
      if (is_array($val)) return $val;
      $arr = json_decode((string)$val, true);
      return is_array($arr) ? $arr : [];
  };

  // ==== Fallback agregasi dari RO list (kalau tlRecap tidak membawa detail) ====
  $sumTotalOs   = (float) $roList->sum(fn($r) => (float)($r->repayment_total_os ?? 0));
  $sumOsLancar  = (float) $roList->sum(fn($r) => (float)($r->repayment_os_lancar ?? 0));
  $rrPctCalc    = $sumTotalOs > 0 ? round(($sumOsLancar / $sumTotalOs) * 100, 2) : 0.0;

  $sumTopupReal = (float) $roList->sum(fn($r) => (float)($r->topup_realisasi ?? 0));
  $sumTopupTgt  = (float) $roList->sum(fn($r) => (float)($r->topup_target ?? 0));
  $topupPctCalc = $sumTopupTgt > 0 ? round(($sumTopupReal / $sumTopupTgt) * 100, 2) : 0.0;

  $sumNoaReal   = (int) $roList->sum(fn($r) => (int)($r->noa_realisasi ?? 0));
  $sumNoaTgt    = (int) $roList->sum(fn($r) => (int)($r->noa_target ?? 0));
  $noaPctCalc   = $sumNoaTgt > 0 ? round(($sumNoaReal / $sumNoaTgt) * 100, 2) : 0.0;

  // DPK weighted by total_os_akhir (kalau ada) supaya masuk akal
  $sumDpkBaseOs = (float) $roList->sum(fn($r) => (float)($r->dpk_total_os_akhir ?? 0));
  $dpkPctCalc   = $sumDpkBaseOs > 0
      ? round(($roList->sum(fn($r) => ((float)($r->dpk_pct ?? 0)) * (float)($r->dpk_total_os_akhir ?? 0))) / $sumDpkBaseOs, 2)
      : (float) ($roList->avg(fn($r) => (float)($r->dpk_pct ?? 0)) ?? 0);

  $sumMigrasiCount = (int) $roList->sum(fn($r) => (int)($r->dpk_migrasi_count ?? 0));
  $sumMigrasiOs    = (float) $roList->sum(fn($r) => (float)($r->dpk_migrasi_os ?? 0));

  // Top3 TL: gabungkan top3 dari RO, lalu sort amount desc, ambil 3
  $mergedTop = [];
  foreach ($roList as $r) {
      $arr = $decodeJsonArr($r->topup_top3_json ?? null);
      foreach ($arr as $x) {
          if (!is_array($x)) continue;
          $cif = (string)($x['cif'] ?? $x['CIF'] ?? '-');
          $amt = (float)($x['amount'] ?? $x['delta_topup'] ?? $x['delta'] ?? 0); // ✅ handle versi lama
          if ($amt <= 0) continue;
          $mergedTop[] = ['cif' => $cif, 'amount' => $amt];
      }
  }
  usort($mergedTop, fn($a,$b) => ($b['amount'] <=> $a['amount']));
  $tlTop3 = array_slice($mergedTop, 0, 3);

  $tlMaxAmt = !empty($mergedTop) ? (float)($mergedTop[0]['amount'] ?? 0) : 0.0;
  $topConcCalc = $sumTopupReal > 0 ? round(($tlMaxAmt / $sumTopupReal) * 100, 2) : 0.0;

  // ==== Ambil value final: prefer tlRecap kalau ada, fallback ke kalkulasi ====
  $rrActualPct      = (float)($tlRecap->repayment_pct ?? $tlRecap->rr_actual_avg ?? 0);
  if ($rrActualPct <= 0 && $sumTotalOs > 0) $rrActualPct = $rrPctCalc;

  $tlTotalOs        = (float)($tlRecap->repayment_total_os ?? 0);
  $tlOsLancar       = (float)($tlRecap->repayment_os_lancar ?? 0);
  if ($tlTotalOs <= 0 && $sumTotalOs > 0) $tlTotalOs = $sumTotalOs;
  if ($tlOsLancar <= 0 && $sumOsLancar > 0) $tlOsLancar = $sumOsLancar;

  $topupReal        = (float)($tlRecap->topup_actual_total ?? $tlRecap->topup_realisasi ?? 0);
  if ($topupReal <= 0 && $sumTopupReal > 0) $topupReal = $sumTopupReal;

  $topupTarget      = (float)($tlRecap->target_topup_total ?? $tlRecap->topup_target ?? 0);
  if ($topupTarget <= 0 && $sumTopupTgt > 0) $topupTarget = $sumTopupTgt;

  $topupPct         = (float)($tlRecap->ach_topup ?? $tlRecap->topup_pct ?? 0);
  if ($topupPct <= 0 && $topupTarget > 0) $topupPct = $topupPctCalc;

  $noaReal          = (int)($tlRecap->noa_actual_total ?? $tlRecap->noa_realisasi ?? 0);
  if ($noaReal <= 0 && $sumNoaReal > 0) $noaReal = $sumNoaReal;

  $noaTarget        = (int)($tlRecap->target_noa_total ?? $tlRecap->noa_target ?? 0);
  if ($noaTarget <= 0 && $sumNoaTgt > 0) $noaTarget = $sumNoaTgt;

  $noaPct           = (float)($tlRecap->ach_noa ?? $tlRecap->noa_pct ?? 0);
  if ($noaPct <= 0 && $noaTarget > 0) $noaPct = $noaPctCalc;

  $dpkActualPct      = (float)($tlRecap->dpk_actual_pct ?? $tlRecap->dpk_pct ?? 0);
  if ($dpkActualPct <= 0 && $sumDpkBaseOs > 0) $dpkActualPct = $dpkPctCalc;

  $migrasiCount     = (int)($tlRecap->dpk_migrasi_count ?? 0);
  $migrasiOs        = (float)($tlRecap->dpk_migrasi_os ?? 0);
  if ($migrasiCount <= 0 && $sumMigrasiCount > 0) $migrasiCount = $sumMigrasiCount;
  if ($migrasiOs <= 0 && $sumMigrasiOs > 0) $migrasiOs = $sumMigrasiOs;

  $topConc = (float)($tlRecap->topup_concentration_pct ?? 0);
  if ($topConc <= 0 && $topConcCalc > 0) $topConc = $topConcCalc;

@endphp

<div class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
  {{-- HEADER --}}
  <div class="px-5 py-4 border-b border-slate-200 flex flex-col md:flex-row md:items-start md:justify-between gap-4">
    <div>
      <div class="text-lg font-extrabold text-slate-900 uppercase">
        KPI {{ $lr }} – {{ $tlRecap->name }}
      </div>

      <div class="text-xs text-slate-500 mt-2 flex flex-wrap gap-2">
        <span class="{{ $chip }}">Repayment: OS Lancar / Total OS</span>
        <span class="{{ $chip }}">TopUp: Delta OS berbasis CIF</span>
        <span class="{{ $chip }}">NOA: CIF baru aktif</span>
        <span class="{{ $chip }}">DPK: Reverse (lebih kecil lebih baik)</span>
      </div>
    </div>

    <div class="text-right">
      <div class="text-xs text-slate-500">TOTAL PI {{ $lr }}</div>
      <div class="text-2xl font-extrabold text-slate-900">
        {{ number_format((float)($tlRecap->pi_total ?? 0), 2) }}
      </div>
    </div>
  </div>

  {{-- TABLE --}}
  <div class="p-4 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-900 text-white">
        <tr>
          <th class="text-left px-3 py-2">KPI</th>
          <th class="text-right px-3 py-2">Target</th>
          <th class="text-right px-3 py-2">Actual</th>
          <th class="text-right px-3 py-2">Pencapaian</th>
          <th class="text-center px-3 py-2">Skor</th>
          <th class="text-right px-3 py-2">Bobot</th>
          <th class="text-right px-3 py-2">PI</th>
        </tr>
      </thead>

      <tbody class="divide-y divide-slate-200">

        {{-- REPAYMENT --}}
        <tr>
          <td class="px-3 py-2 font-semibold">Repayment Rate</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($tlRecap->target_rr_pct ?? 100) }}</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($rrActualPct) }}</td>
          <td class="px-3 py-2 text-right">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($rrActualPct) }}">
              {{ $fmtPct($rrActualPct) }}
            </span>
          </td>
          <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_repayment ?? $tlRecap->repayment_score ?? 0), 2) }}</td>
          <td class="px-3 py-2 text-right">{{ (int)round(((float)($weights['repayment'] ?? 0))*100) }}%</td>
          <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($tlRecap->pi_repayment ?? 0), 2) }}</td>
        </tr>

        <tr class="bg-slate-50">
          <td class="px-3 py-2 text-slate-600">Detail RR</td>
          <td class="px-3 py-2 text-right text-slate-600">Total OS</td>
          <td class="px-3 py-2 text-right text-slate-700">{{ $fmtRp($tlTotalOs) }}</td>
          <td class="px-3 py-2 text-right text-slate-600">OS Lancar</td>
          <td class="px-3 py-2 text-right text-slate-700">{{ $fmtRp($tlOsLancar) }}</td>
          <td class="px-3 py-2 text-right text-slate-600">Basis</td>
          <td class="px-3 py-2 text-right text-slate-700">FT=0/0</td>
        </tr>

                {{-- DPK --}}
        <tr>
          <td class="px-3 py-2 font-semibold">Pemburukan DPK</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($tlRecap->target_dpk_pct ?? 0) }}</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($dpkActualPct) }}</td>
          <td class="px-3 py-2 text-right">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeReverse($dpkActualPct, $tlRecap->target_dpk_pct ?? 1) }}">
              {{ $fmtPct($tlRecap->ach_dpk ?? 0) }}
            </span>
          </td>
          <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_dpk ?? $tlRecap->dpk_score ?? 0), 2) }}</td>
          <td class="px-3 py-2 text-right">{{ (int)round(((float)($weights['dpk'] ?? 0))*100) }}%</td>
          <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($tlRecap->pi_dpk ?? 0), 2) }}</td>
        </tr>

        <tr class="bg-slate-50">
          <td class="px-3 py-2 text-slate-600">Detail Migrasi LT→DPK</td>
          <td class="px-3 py-2 text-right text-slate-600">Count</td>
          <td class="px-3 py-2 text-right text-slate-700">{{ number_format((int)$migrasiCount) }}</td>
          <td class="px-3 py-2 text-right text-slate-600">OS Migrasi</td>
          <td class="px-3 py-2 text-right text-slate-700">{{ $fmtRp($migrasiOs) }}</td>
          <td colspan="2"></td>
        </tr>

        {{-- TOPUP --}}
        <tr>
          <td class="px-3 py-2 font-semibold">Top Up (Delta OS CIF)</td>
          <td class="px-3 py-2 text-right">{{ $fmtRp($topupTarget) }}</td>
          <td class="px-3 py-2 text-right">{{ $fmtRp($topupReal) }}</td>
          <td class="px-3 py-2 text-right">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($topupPct) }}">
              {{ $fmtPct($topupPct) }}
            </span>
          </td>
          <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_topup ?? $tlRecap->topup_score ?? 0), 2) }}</td>
          <td class="px-3 py-2 text-right">{{ (int)round(((float)($weights['topup'] ?? 0))*100) }}%</td>
          <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($tlRecap->pi_topup ?? 0), 2) }}</td>
        </tr>

        <tr class="bg-slate-50">
          <td class="px-3 py-2 text-slate-600">Konsentrasi</td>
          <td colspan="6" class="px-3 py-2">
            <span class="px-3 py-1 rounded-full text-xs font-semibold border
              {{ $topConc >= 60 ? 'bg-amber-100 text-amber-800 border-amber-200' : 'bg-emerald-100 text-emerald-800 border-emerald-200' }}">
              Top1 / Total: {{ number_format($topConc,2) }}%
              @if($topConc >= 60) • High Concentration @endif
            </span>

            @if(!empty($tlTop3))
              <div class="mt-2 flex flex-wrap gap-2">
                @foreach($tlTop3 as $row)
                  <span class="px-3 py-1 rounded-full text-xs border bg-white border-slate-200">
                    {{ ($row['cif'] ?? '-') }} • {{ $fmtRp($row['amount'] ?? 0) }}
                  </span>
                @endforeach
              </div>
            @endif
          </td>
        </tr>

        {{-- NOA --}}
        <tr>
          <td class="px-3 py-2 font-semibold">NOA Pengembangan</td>
          <td class="px-3 py-2 text-right">{{ number_format((int)$noaTarget) }}</td>
          <td class="px-3 py-2 text-right">{{ number_format((int)$noaReal) }}</td>
          <td class="px-3 py-2 text-right">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($noaPct) }}">
              {{ $fmtPct($noaPct) }}
            </span>
          </td>
          <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_noa ?? $tlRecap->noa_score ?? 0), 2) }}</td>
          <td class="px-3 py-2 text-right">{{ (int)round(((float)($weights['noa'] ?? 0))*100) }}%</td>
          <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($tlRecap->pi_noa ?? 0), 2) }}</td>
        </tr>


      </tbody>

      <tfoot>
        <tr class="bg-yellow-200">
          <td colspan="6" class="px-3 py-2 font-extrabold text-right">TOTAL PI {{ $lr }}</td>
          <td class="px-3 py-2 font-extrabold text-right">{{ number_format((float)($tlRecap->pi_total ?? 0), 2) }}</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
@endif

  @foreach($items as $it)
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-3">
        <div>
          <div class="text-lg font-extrabold text-slate-900 uppercase">{{ $it->name }}</div>
          <div class="text-xs text-slate-500">
            RO • AO Code: <b>{{ $it->ao_code ?: '-' }}</b>
            @if(($it->baseline_ok ?? 0) == 1)
              <span class="ml-2 inline-flex rounded-full bg-emerald-100 text-emerald-800 px-3 py-1 text-xs font-semibold">
                BASELINE OK
              </span>
            @endif
          </div>
        </div>

        <div class="text-right">
          <div class="text-xs text-slate-500">Total PI</div>
          <div class="text-xl font-extrabold text-slate-900">
            {{ number_format((float)($it->pi_total ?? 0), 2) }}
          </div>
        </div>
      </div>

      <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-orange-500 text-white">
            <tr>
              <th class="text-left px-3 py-2">KPI</th>
              <th class="text-right px-3 py-2">Target</th>
              <th class="text-right px-3 py-2">Actual</th>
              <th class="text-right px-3 py-2">Pencapaian</th>
              <th class="text-center px-3 py-2">Skor</th>
              <th class="text-right px-3 py-2">Bobot</th>
              <th class="text-right px-3 py-2">PI</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-200">
            @once
                @php
                $kpiBadgeNormal = function ($ach) {
                    $ach = (float)($ach ?? 0);
                    if ($ach >= 100) return 'bg-emerald-100 text-emerald-800 border border-emerald-200';
                    if ($ach >= 80)  return 'bg-amber-100 text-amber-800 border border-amber-200';
                    return 'bg-rose-100 text-rose-800 border border-rose-200';
                };

                $kpiBadgeReverse = function ($actual, $target) {
                    $actual = (float)($actual ?? 0);
                    $target = (float)($target ?? 0);
                    if ($target <= 0) return 'bg-slate-100 text-slate-600 border border-slate-200';

                    $ratio = ($actual / $target) * 100.0; // <=100 bagus
                    if ($ratio <= 100) return 'bg-emerald-100 text-emerald-800 border border-emerald-200';
                    if ($ratio <= 120) return 'bg-amber-100 text-amber-800 border border-amber-200';
                    return 'bg-rose-100 text-rose-800 border border-rose-200';
                };
                @endphp
            @endonce

            {{-- Repayment --}}
            <tr>
                <td class="px-3 py-2 font-semibold">Repayment Rate</td>

                {{-- Target RR --}}
                <td class="px-3 py-2 text-right">
                    {{ number_format((float)($it->target_rr_pct ?? 100), 2) }}%
                </td>

                {{-- Actual RR --}}
                <td class="px-3 py-2 text-right">
                    {{ number_format((float)($it->repayment_pct_display ?? 0), 2) }}%
                </td>

                {{-- Pencapaian RR (Actual/Target) --}}
                <td class="px-3 py-2 text-right">  
                    <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($it->ach_rr ?? 0) }}">
                        {{ number_format((float)($it->ach_rr ?? 0),2) }}%
                    </span>
                </td>


                <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->repayment_score ?? 0), 2) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)round(($weights['repayment'] ?? 0)*100) }}%</td>
                <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_repayment ?? 0), 2) }}</td>
            </tr>
            
           {{-- DPK --}}
            @php
                $tDpk = (float)($it->target_dpk_pct ?? 0);  // target dalam % (contoh 1.00 artinya 1%)
                $aDpk = (float)($it->dpk_pct ?? 0);         // actual dalam % (contoh 7.33)

                // Pencapaian (lower is better):
                // - kalau actual = 0 => 100% (perfect)
                // - kalau target = 0 => 0 (tidak bisa dihitung)
                // - kalau actual <= target => >=100 (bagus)
                $achDpk = 0.0;
                if ($tDpk > 0) {
                    $achDpk = ($aDpk <= 0) ? 100.0 : round(($tDpk / $aDpk) * 100.0, 2);
                }
            @endphp

            <tr>
            <td class="px-3 py-2 font-semibold">Pemburukan DPK (Migrasi)</td>

            {{-- TARGET --}}
            <td class="px-3 py-2 text-right">
                {{ number_format((float)($it->target_dpk_pct ?? 0), 2) }}%
            </td>

            {{-- ACTUAL --}}
            <td class="px-3 py-2 text-right">
                {{ number_format((float)($it->dpk_pct ?? 0), 2) }}%
            </td>

            {{-- PENCAPAIAN --}}
            <td class="px-3 py-2 text-right">
                <span class="px-3 py-1 rounded-full text-xs font-semibold 
                    {{ $kpiBadgeReverse($it->dpk_pct ?? 0, $it->target_dpk_pct ?? 1) }}">
                    {{ number_format((float)($it->ach_dpk ?? 0),2) }}%
                </span>
            </td>

            {{-- SKOR --}}
            <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->dpk_score ?? 0), 2) }}</td>

            {{-- BOBOT --}}
            <td class="px-3 py-2 text-right">{{ (int)round(($weights['dpk'] ?? 0)*100) }}%</td>

            {{-- PI --}}
            <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_dpk ?? 0), 2) }}</td>
            </tr>

            <tr class="bg-slate-50">
                <td class="px-3 py-2 text-slate-600">Detail DPK Migrasi</td>
                <td class="px-3 py-2 text-slate-600 text-center">Count</td>
                <td class="px-3 py-2 text-slate-600 text-right">{{ number_format((int)($it->dpk_migrasi_count ?? 0)) }}</td>
                <td class="px-3 py-2 text-slate-600 text-center">OS Migrasi</td>
                <td class="px-3 py-2 text-slate-600 text-right" colspan="3">
                    Rp {{ number_format((int)($it->dpk_migrasi_os ?? 0),0,',','.') }}
                </td>
            </tr>

            {{-- TopUp --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Target Realisasi TU (Top Up)</td>
              <td class="px-3 py-2 text-right">
                Rp {{ number_format((int)($it->target_topup ?? 0),0,',','.') }}
              </td>
              <td class="px-3 py-2 text-right">
                Rp {{ number_format((int)($it->topup_realisasi ?? 0),0,',','.') }}
              </td>
              <td class="px-3 py-2 text-right">
                <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($it->ach_rr ?? 0) }}">
                    {{ number_format((float)($it->ach_topup ?? 0),2) }}%
                </span>
              </td>

              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->topup_score ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['topup'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_topup ?? 0), 2) }}</td>
            </tr>

            {{-- NOA --}}
            <tr>
              <td class="px-3 py-2 font-semibold">NOA Pengembangan</td>
              <td class="px-3 py-2 text-right">{{ number_format((int)($it->target_noa ?? 0)) }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((int)($it->noa_realisasi ?? 0)) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($it->ach_rr ?? 0) }}">
                    {{ number_format((float)($it->ach_noa ?? 0),2) }}%
                </span>
              </td>

              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->noa_score ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['noa'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_noa ?? 0), 2) }}</td>
            </tr>


          </tbody>

          <tfoot>
            <tr class="bg-yellow-200">
              <td colspan="6" class="px-3 py-2 font-extrabold text-right">TOTAL</td>
              <td class="px-3 py-2 font-extrabold text-right">
                {{ number_format((float)($it->pi_total ?? 0),2) }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  @endforeach

</div>
