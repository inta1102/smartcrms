<div class="space-y-6">

@php
  // ==========================================================
  // GLOBAL HELPERS (1x)
  // ==========================================================
  $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $d=2) => number_format((float)($n ?? 0), $d) . '%';

  $chipCls = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200';

  // Badge untuk KPI normal (bigger better) berdasarkan ACH %
  $kpiBadgeNormal = function ($ach) {
      $ach = (float)($ach ?? 0);
      if ($ach >= 100) return 'bg-emerald-100 text-emerald-800 border border-emerald-200';
      if ($ach >= 80)  return 'bg-amber-100 text-amber-800 border border-amber-200';
      return 'bg-rose-100 text-rose-800 border border-rose-200';
  };

  // Badge untuk KPI reverse (lower better) berdasarkan (actual/target)*100
  $kpiBadgeReverse = function ($actual, $target) {
      $actual = (float)($actual ?? 0);
      $target = (float)($target ?? 0);
      if ($target <= 0) return 'bg-slate-100 text-slate-600 border border-slate-200';

      $ratio = ($actual / $target) * 100.0; // <=100 bagus
      if ($ratio <= 100) return 'bg-emerald-100 text-emerald-800 border border-emerald-200';
      if ($ratio <= 120) return 'bg-amber-100 text-amber-800 border border-amber-200';
      return 'bg-rose-100 text-rose-800 border border-rose-200';
  };

  // label akumulasi (opsional dari controller)
  $accText = null;
  if (!empty($startYtd) && !empty($endYtd)) {
      $accText = 'Akumulasi ' . \Carbon\Carbon::parse($startYtd)->format('d M Y') . ' – ' . \Carbon\Carbon::parse($endYtd)->format('d M Y');
  }

  $modeLabel = strtoupper($mode ?? '');
  $modeChip = match (strtolower($mode ?? '')) {
      'realtime' => 'bg-emerald-100 text-emerald-800 border border-emerald-200',
      'eom'      => 'bg-indigo-100 text-indigo-800 border border-indigo-200',
      default    => 'bg-slate-100 text-slate-700 border border-slate-200',
  };
@endphp

{{-- ==========================================================
   TL / LEADER RECAP (lebih ringkas + konsisten)
   ========================================================== --}}
@if(!empty($tlRecap))
@php
  $lr = strtoupper($tlRecap->leader_role ?? 'LEADER');

  // daftar RO yang tampil
  $roList = collect($items ?? []);

  // ===== fallback target akumulasi dari items (karena item sudah YTD) =====
  $sumTopupTarget = (float) $roList->sum(fn($r) => (float)($r->target_topup ?? 0));
  $sumNoaTarget   = (int)   $roList->sum(fn($r) => (int)($r->target_noa ?? 0));

  // RR target + fallback ach rr
  $rrTarget   = (float)($tlRecap->target_rr_pct ?? 100);
  $rrActualPct = (float)($tlRecap->rr_actual_avg ?? 0);
  if ($rrActualPct <= 0 && $rrPctCalc > 0) $rrActualPct = $rrPctCalc;

  $rrAch = (float)($tlRecap->ach_rr ?? 0);
  if ($rrAch <= 0 && $rrTarget > 0) $rrAch = round(($rrActualPct / $rrTarget) * 100, 2);

  // TopUp target/ach fallback
  $topupTarget = (float)($tlRecap->target_topup_total ?? 0);
  if ($topupTarget <= 0 && $sumTopupTarget > 0) $topupTarget = $sumTopupTarget;

  $topupReal   = (float)($tlRecap->topup_actual_total ?? 0);
  $topupAch    = (float)($tlRecap->ach_topup ?? 0);
  if ($topupAch <= 0 && $topupTarget > 0) $topupAch = round(($topupReal/$topupTarget)*100, 2);

  // NOA target/ach fallback
  $noaTarget = (int)($tlRecap->target_noa_total ?? 0);
  if ($noaTarget <= 0 && $sumNoaTarget > 0) $noaTarget = $sumNoaTarget;

  $noaReal  = (int)($tlRecap->noa_actual_total ?? 0);
  $noaAch   = (float)($tlRecap->ach_noa ?? 0);
  if ($noaAch <= 0 && $noaTarget > 0) $noaAch = round(($noaReal/$noaTarget)*100, 2);

  // Top3 normalize
  $tlTop3 = $tlRecap->topup_top3 ?? [];
  if (is_string($tlTop3)) {
      $tmp = json_decode($tlTop3, true);
      $tlTop3 = is_array($tmp) ? $tmp : [];
  }
@endphp

<div class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">

  {{-- HEADER --}}
  <div class="px-5 py-4 border-b border-slate-200 flex flex-col md:flex-row md:items-start md:justify-between gap-4">
    <div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="text-lg font-extrabold text-slate-900 uppercase">
          KPI {{ $lr }} – {{ $tlRecap->name ?? '-' }}
        </div>

        @if($accText)
          <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border bg-indigo-50 text-indigo-700 border-indigo-200">
            {{ $accText }}
          </span>
        @endif

        @if(!empty($modeLabel))
          <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border {{ $modeChip }}">
            MODE {{ $modeLabel }}
          </span>
        @endif

        @if(!empty($tlRecap->scope_count))
          <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200">
            Scope: {{ (int)$tlRecap->scope_count }} RO
          </span>
        @endif
      </div>

      <div class="text-xs text-slate-500 mt-2 flex flex-wrap gap-2">
        <span class="{{ $chipCls }}">RR: OS Lancar / Total OS</span>
        <span class="{{ $chipCls }}">DPK: Reverse (lebih kecil lebih baik)</span>
        <span class="{{ $chipCls }}">TopUp: Akumulasi realisasi</span>
        <span class="{{ $chipCls }}">NOA: Akumulasi jumlah</span>
      </div>
    </div>

    <div class="text-right">
      <div class="text-xs text-slate-500">TOTAL PI {{ $lr }}</div>
      <div class="text-2xl font-extrabold text-slate-900">
        {{ number_format((float)($tlRecap->pi_total ?? 0), 2) }}
      </div>
      <div class="text-xs text-slate-500 mt-1">
        (RR {{ (int)round(($weights['repayment'] ?? 0)*100) }}% •
         DPK {{ (int)round(($weights['dpk'] ?? 0)*100) }}% •
         TU {{ (int)round(($weights['topup'] ?? 0)*100) }}% •
         NOA {{ (int)round(($weights['noa'] ?? 0)*100) }}%)
      </div>
    </div>
  </div>

  {{-- MINI CARDS --}}
  <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
    <div class="rounded-xl border border-slate-200 bg-white p-3">
      <div class="text-xs text-slate-500">RR (Akumulasi)</div>
      <div class="text-lg font-extrabold text-slate-900">{{ $fmtPct($rrActualPct) }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Ach: {{ $fmtPct($tlRecap->ach_rr ?? $rrActualPct) }} • Skor {{ number_format((float)($tlRecap->score_repayment ?? 0),2) }}
      </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-3">
      <div class="text-xs text-slate-500">DPK (Migrasi)</div>
      <div class="text-lg font-extrabold text-slate-900">{{ $fmtPct($dpkActual) }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Ach: {{ $fmtPct($dpkAch) }} • Skor {{ number_format((float)($tlRecap->score_dpk ?? 0),2) }}
      </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-3">
      <div class="text-xs text-slate-500">TopUp (Akumulasi)</div>
      <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($topupReal) }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Ach: {{ $fmtPct($topupAch) }} • Skor {{ number_format((float)($tlRecap->score_topup ?? 0),2) }}
      </div>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-3">
      <div class="text-xs text-slate-500">NOA (Akumulasi)</div>
      <div class="text-lg font-extrabold text-slate-900">{{ number_format((int)$noaReal) }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Ach: {{ $fmtPct($noaAch) }} • Skor {{ number_format((float)($tlRecap->score_noa ?? 0),2) }}
      </div>
    </div>
  </div>

  {{-- TABLE --}}
  <div class="px-4 pb-4 overflow-x-auto">
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
        {{-- RR --}}
        <tr>
          <td class="px-3 py-2 font-semibold">Repayment Rate</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($tlRecap->target_rr_pct ?? 100) }}</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($rrActualPct) }}</td>
          <td class="px-3 py-2 text-right">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($tlRecap->ach_rr ?? $rrActualPct) }}">
              {{ $fmtPct($tlRecap->ach_rr ?? $rrActualPct) }}
            </span>
          </td>
          <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_repayment ?? 0), 2) }}</td>
          <td class="px-3 py-2 text-right">{{ (int)round(((float)($weights['repayment'] ?? 0))*100) }}%</td>
          <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($tlRecap->pi_repayment ?? 0), 2) }}</td>
        </tr>

        {{-- DPK --}}
        <tr>
          <td class="px-3 py-2 font-semibold">Pemburukan DPK (Migrasi)</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($dpkTarget) }}</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($dpkActual) }}</td>
          <td class="px-3 py-2 text-right">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeReverse($dpkActual, $dpkTarget) }}">
              {{ $fmtPct($dpkAch) }}
            </span>
          </td>
          <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_dpk ?? 0), 2) }}</td>
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
          <td class="px-3 py-2 font-semibold">Target Realisasi TU (Top Up)</td>
          <td class="px-3 py-2 text-right">{{ $fmtRp($topupTarget) }}</td>
          <td class="px-3 py-2 text-right">{{ $fmtRp($topupReal) }}</td>
          <td class="px-3 py-2 text-right">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($topupAch) }}">
              {{ $fmtPct($topupAch) }}
            </span>
          </td>
          <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_topup ?? 0), 2) }}</td>
          <td class="px-3 py-2 text-right">{{ (int)round(((float)($weights['topup'] ?? 0))*100) }}%</td>
          <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($tlRecap->pi_topup ?? 0), 2) }}</td>
        </tr>

        <tr class="bg-slate-50">
          <td class="px-3 py-2 text-slate-600">Konsentrasi TopUp</td>
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
                    {{ is_string($row) ? $row : (($row['cif'] ?? '-') . ' • ' . $fmtRp($row['amount'] ?? 0)) }}
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
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($noaAch) }}">
              {{ $fmtPct($noaAch) }}
            </span>
          </td>
          <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_noa ?? 0), 2) }}</td>
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

{{-- ==========================================================
   RO DETAIL (per RO)
   ========================================================== --}}
@foreach($items as $it)
@php
  $baselineOk = ((int)($it->baseline_ok ?? 0) === 1);

  $rrPct   = (float)($it->repayment_pct_display ?? 0);
  $rrAch   = (float)($it->ach_rr ?? 0);

  $dpkAct  = (float)($it->dpk_pct_display ?? $it->dpk_pct ?? 0);
  $dpkAch  = (float)($it->ach_dpk ?? 0);
  $dpkTgt  = (float)($it->target_dpk_pct ?? 1.0);

  $topReal = (float)($it->topup_realisasi ?? 0);
  $topAch  = (float)($it->ach_topup ?? 0);

  $noaReal = (int)($it->noa_realisasi ?? 0);
  $noaAch  = (float)($it->ach_noa ?? 0);
@endphp

<div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
  <div class="px-5 py-4 border-b border-slate-200 flex flex-col md:flex-row md:items-start md:justify-between gap-4">
    <div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="text-lg font-extrabold text-slate-900 uppercase">{{ $it->name }}</div>

        @if($accText)
          <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border bg-indigo-50 text-indigo-700 border-indigo-200">
            {{ $accText }}
          </span>
        @endif

        @if(!empty($modeLabel))
          <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border {{ $modeChip }}">
            MODE {{ $modeLabel }}
          </span>
        @endif

        @if($baselineOk)
          <span class="inline-flex rounded-full bg-emerald-100 text-emerald-800 px-3 py-1 text-xs font-semibold border border-emerald-200">
            BASELINE OK
          </span>
        @else
          <span class="inline-flex rounded-full bg-rose-100 text-rose-800 px-3 py-1 text-xs font-semibold border border-rose-200">
            BASELINE CHECK
          </span>
        @endif
      </div>

      <div class="text-xs text-slate-500 mt-1">
        RO • AO Code: <b>{{ $it->ao_code ?: '-' }}</b>
      </div>
    </div>

    <div class="text-right">
      <div class="text-xs text-slate-500">Total PI</div>
      <div class="text-xl font-extrabold text-slate-900">
        {{ number_format((float)($it->pi_total ?? 0), 2) }}
      </div>
      <div class="text-xs text-slate-500 mt-1">
        (RR {{ (int)round(($weights['repayment'] ?? 0)*100) }}% •
         DPK {{ (int)round(($weights['dpk'] ?? 0)*100) }}% •
         TU {{ (int)round(($weights['topup'] ?? 0)*100) }}% •
         NOA {{ (int)round(($weights['noa'] ?? 0)*100) }}%)
      </div>
    </div>
  </div>

  {{-- MINI KPI CARDS --}}
  <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
    <div class="rounded-xl border border-slate-200 bg-white p-3">
      <div class="text-xs text-slate-500">RR</div>
      <div class="text-lg font-extrabold text-slate-900">{{ $fmtPct($rrPct) }}</div>
      <div class="text-xs text-slate-500 mt-1">Ach {{ $fmtPct($rrAch) }} • Skor {{ number_format((float)($it->repayment_score ?? 0),2) }}</div>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-3">
      <div class="text-xs text-slate-500">DPK</div>
      <div class="text-lg font-extrabold text-slate-900">{{ $fmtPct($dpkAct) }}</div>
      <div class="text-xs text-slate-500 mt-1">Ach {{ $fmtPct($dpkAch) }} • Skor {{ number_format((float)($it->dpk_score ?? 0),2) }}</div>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-3">
      <div class="text-xs text-slate-500">TopUp</div>
      <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($topReal) }}</div>
      <div class="text-xs text-slate-500 mt-1">Ach {{ $fmtPct($topAch) }} • Skor {{ number_format((float)($it->topup_score ?? 0),2) }}</div>
    </div>
    <div class="rounded-xl border border-slate-200 bg-white p-3">
      <div class="text-xs text-slate-500">NOA</div>
      <div class="text-lg font-extrabold text-slate-900">{{ number_format((int)$noaReal) }}</div>
      <div class="text-xs text-slate-500 mt-1">Ach {{ $fmtPct($noaAch) }} • Skor {{ number_format((float)($it->noa_score ?? 0),2) }}</div>
    </div>
  </div>

  {{-- TABLE --}}
  <div class="px-4 pb-4 overflow-x-auto">
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
        {{-- RR --}}
        <tr>
          <td class="px-3 py-2 font-semibold">Repayment Rate</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($it->target_rr_pct ?? 100) }}</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($rrPct) }}</td>
          <td class="px-3 py-2 text-right">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($rrAch) }}">
              {{ $fmtPct($rrAch) }}
            </span>
          </td>
          <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->repayment_score ?? 0), 2) }}</td>
          <td class="px-3 py-2 text-right">{{ (int)round(($weights['repayment'] ?? 0)*100) }}%</td>
          <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_repayment ?? 0), 2) }}</td>
        </tr>

        {{-- DPK --}}
        <tr>
          <td class="px-3 py-2 font-semibold">Pemburukan DPK (Migrasi)</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($dpkTgt) }}</td>
          <td class="px-3 py-2 text-right">{{ $fmtPct($dpkAct) }}</td>
          <td class="px-3 py-2 text-right">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeReverse($dpkAct, $dpkTgt) }}">
              {{ $fmtPct($dpkAch) }}
            </span>
          </td>
          <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->dpk_score ?? 0), 2) }}</td>
          <td class="px-3 py-2 text-right">{{ (int)round(($weights['dpk'] ?? 0)*100) }}%</td>
          <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_dpk ?? 0), 2) }}</td>
        </tr>

        <tr class="bg-slate-50">
          <td class="px-3 py-2 text-slate-600">Detail DPK Migrasi</td>
          <td class="px-3 py-2 text-slate-600 text-center">Count</td>
          <td class="px-3 py-2 text-slate-600 text-right">{{ number_format((int)($it->dpk_migrasi_count ?? 0)) }}</td>
          <td class="px-3 py-2 text-slate-600 text-center">OS Migrasi</td>
          <td class="px-3 py-2 text-slate-600 text-right" colspan="3">{{ $fmtRp($it->dpk_migrasi_os ?? 0) }}</td>
        </tr>

        {{-- TOPUP --}}
        <tr>
          <td class="px-3 py-2 font-semibold">Target Realisasi TU (Top Up)</td>
          <td class="px-3 py-2 text-right">{{ $fmtRp($it->target_topup ?? 0) }}</td>
          <td class="px-3 py-2 text-right">{{ $fmtRp($topReal) }}</td>
          <td class="px-3 py-2 text-right">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($topAch) }}">
              {{ $fmtPct($topAch) }}
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
          <td class="px-3 py-2 text-right">{{ number_format((int)$noaReal) }}</td>
          <td class="px-3 py-2 text-right">
            <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($noaAch) }}">
              {{ $fmtPct($noaAch) }}
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
          <td class="px-3 py-2 font-extrabold text-right">{{ number_format((float)($it->pi_total ?? 0),2) }}</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
@endforeach

</div>