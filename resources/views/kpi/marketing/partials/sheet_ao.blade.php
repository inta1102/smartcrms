@php
  $wUmkm = [
    'noa' => 0.30,
    'os'  => 0.20,
    'rr'  => 0.25,
    'community' => 0.20,
    'daily' => 0.05,
  ];

  $fmtRp  = fn($n) => 'Rp ' . number_format((int)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n) => number_format((float)($n ?? 0), 2) . '%';

  $rrBadge = function($rr) {
    $rr = (float)($rr ?? 0);
    if ($rr >= 90) return ['label'=>'AMAN', 'cls'=>'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200'];
    if ($rr >= 80) return ['label'=>'WASPADA', 'cls'=>'bg-yellow-100 text-yellow-700 ring-1 ring-yellow-200'];
    return ['label'=>'RISIKO', 'cls'=>'bg-rose-100 text-rose-700 ring-1 ring-rose-200'];
  };

  // RR risk threshold
  $rrRiskThreshold = 80.0;

  // ✅ Mode akumulasi label (YTD)
  $startYtdLabel = !empty($startYtd) ? \Carbon\Carbon::parse($startYtd)->translatedFormat('d M Y') : null;
  $endYtdLabel   = !empty($endYtd) ? \Carbon\Carbon::parse($endYtd)->translatedFormat('d M Y') : null;
  $accLabel      = ($startYtdLabel && $endYtdLabel) ? "Akumulasi {$startYtdLabel} – {$endYtdLabel}" : 'Akumulasi Jan – Periode';
@endphp

{{-- ========= Enhancement #2: anchor + highlight effect ========= --}}
<style>
  .ao-flash {
    animation: aoFlash 1.2s ease-out 1;
  }
  @keyframes aoFlash {
    0%   { box-shadow: 0 0 0 0 rgba(59,130,246,0.0); background: rgba(59,130,246,0.08); }
    35%  { box-shadow: 0 0 0 6px rgba(59,130,246,0.15); background: rgba(59,130,246,0.08); }
    100% { box-shadow: 0 0 0 0 rgba(59,130,246,0.0); background: transparent; }
  }
</style>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    // --- scroll to AO card if hash exists ---
    if (window.location.hash && window.location.hash.startsWith('#ao-')) {
      const el = document.querySelector(window.location.hash);
      if (el) {
        el.classList.add('ao-flash');
        setTimeout(() => el.classList.remove('ao-flash'), 1300);
      }
    }

    // --- Enhancement #1: risk-only filter for ranking ---
    const chk = document.getElementById('filterRiskOnly');
    const rows = document.querySelectorAll('[data-rank-row="1"]');

    const applyFilter = () => {
      const onlyRisk = chk && chk.checked;
      rows.forEach(tr => {
        const rr = parseFloat(tr.getAttribute('data-rr') || '0');
        const isRisk = rr < {{ $rrRiskThreshold }};
        tr.style.display = (onlyRisk && !isRisk) ? 'none' : '';
      });
    };

    if (chk) {
      chk.addEventListener('change', applyFilter);
      applyFilter();
    }
  });
</script>

{{-- =========================
     ✅ BANNER MODE (YTD / AKUMULASI)
     ========================= --}}
<div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
  <div class="px-5 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <div class="text-sm font-extrabold text-slate-900">Mode Perhitungan: <span class="text-indigo-700">AKUMULASI (YTD)</span></div>
      <div class="text-xs text-slate-500">
        Target & Actual dihitung akumulasi dari awal tahun sampai periode aktif (RR dihitung weighted).
      </div>
    </div>
    <div class="flex items-center gap-2">
      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200">
        {{ $accLabel }}
      </span>
      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-slate-50 text-slate-700 ring-1 ring-slate-200">
        Skor 1–6
      </span>
    </div>
  </div>
</div>


{{-- =========================
     TLUM – REKAP SCOPE (AKUMULASI/YTD)
     ========================= --}}
@if(!empty($tlum))
  @php
    $b = $rrBadge($tlum->rr_actual ?? 0);

    $trendDelta = !empty($trend) ? (float)($trend->delta ?? 0) : null;
    $trendCls   = ($trendDelta !== null && $trendDelta >= 0) ? 'text-emerald-600' : 'text-rose-600';
    $trendIcon  = ($trendDelta !== null && $trendDelta >= 0) ? '▲' : '▼';

    // bobot TLUM (ambil dari wUmkm biar konsisten)
    $wNoa = (float)($wUmkm['noa'] ?? 0.30);
    $wOs  = (float)($wUmkm['os'] ?? 0.20);
    $wRr  = (float)($wUmkm['rr'] ?? 0.25);
    $wCom = (float)($wUmkm['community'] ?? 0.20);

    $fmtW = fn($w) => number_format($w * 100, 0) . '%';
  @endphp

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
    {{-- HEADER --}}
    <div class="px-5 py-4 border-b border-slate-200">
      <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
        <div class="space-y-1">
          <div class="text-2xl font-extrabold tracking-tight text-slate-900">TL UMKM – Rekap Scope</div>
          <div class="text-sm text-slate-500">
            Agregat dari seluruh AO UMKM di bawah TLUM (RR dihitung weighted).
          </div>

          <div class="mt-2 flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200">
              {{ $accLabel }}
            </span>
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] bg-slate-50 text-slate-700 ring-1 ring-slate-200">
              Mode: Akumulasi (YTD)
            </span>

            {{-- MoM Trend --}}
            @if(!empty($trend))
              <span class="inline-flex items-center gap-1 text-[11px] text-slate-600">
                <span class="text-slate-400">MoM vs {{ \Carbon\Carbon::parse($trend->prev_period)->translatedFormat('M Y') }}:</span>
                <span class="font-bold {{ $trendCls }}">{{ $trendIcon }} {{ number_format((float)$trend->delta, 2) }}</span>
                <span class="text-slate-400">
                  (prev {{ number_format((float)$trend->prev_pi,2) }} → now {{ number_format((float)$trend->cur_pi,2) }})
                </span>
              </span>
            @endif
          </div>
        </div>

        <div class="flex items-start justify-between lg:justify-end gap-3">
          <div class="text-right">
            <div class="text-xs text-slate-500">Total PI</div>
            <div class="text-4xl font-black text-slate-900 leading-none">
              {{ number_format((float)($tlum->pi_total ?? 0),2) }}
            </div>
          </div>

          <div class="pt-1">
            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold {{ $b['cls'] }}">
              RR {{ $b['label'] }} • {{ number_format((float)($tlum->rr_actual ?? 0),2) }}%
            </span>
          </div>
        </div>
      </div>
    </div>

    {{-- TABLE --}}
    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-emerald-700 text-white">
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
          <tr>
            <td class="px-3 py-2 font-semibold">
              Pertumbuhan NOA (Scope)
              <div class="text-[11px] text-slate-500 mt-0.5">Akumulasi jumlah NOA (Jan–Periode)</div>
            </td>
            <td class="px-3 py-2 text-right">{{ (int)($tlum->noa_target ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ (int)($tlum->noa_actual ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtPct($tlum->noa_pct ?? 0) }}</td>
            <td class="px-3 py-2 text-center font-extrabold">{{ (int)($tlum->score_noa ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtW($wNoa) }}</td>
            <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($tlum->pi_noa ?? 0),2) }}</td>
          </tr>

          <tr>
            <td class="px-3 py-2 font-semibold">
              Realisasi (Akumulasi) (Scope)
              <div class="text-[11px] text-slate-500 mt-0.5">Total disbursement akumulasi (Jan–Periode)</div>
            </td>
            <td class="px-3 py-2 text-right">{{ $fmtRp($tlum->os_target ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtRp($tlum->os_actual ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtPct($tlum->os_pct ?? 0) }}</td>
            <td class="px-3 py-2 text-center font-extrabold">{{ (int)($tlum->score_os ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtW($wOs) }}</td>
            <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($tlum->pi_os ?? 0),2) }}</td>
          </tr>

          <tr>
            <td class="px-3 py-2 font-semibold">
              Kualitas Kredit (RR) – Weighted
              <div class="text-[11px] text-slate-500 mt-0.5">RR scope = OS current / OS total (weighted)</div>
            </td>
            <td class="px-3 py-2 text-right">{{ number_format((float)($tlum->rr_target ?? 100),2) }}%</td>
            <td class="px-3 py-2 text-right">{{ number_format((float)($tlum->rr_actual ?? 0),2) }}%</td>
            <td class="px-3 py-2 text-right">{{ $fmtPct($tlum->rr_pct ?? $tlum->rr_actual ?? 0) }}</td>
            <td class="px-3 py-2 text-center font-extrabold">{{ (int)($tlum->score_rr ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtW($wRr) }}</td>
            <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($tlum->pi_rr ?? 0),2) }}</td>
          </tr>

          <tr>
            <td class="px-3 py-2 font-semibold">
              Grab to Community (Scope)
              <div class="text-[11px] text-slate-500 mt-0.5">Total aktivitas community akumulasi (Jan–Periode)</div>
            </td>
            <td class="px-3 py-2 text-right">{{ (int)($tlum->com_target ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ (int)($tlum->com_actual ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtPct($tlum->com_pct ?? 0) }}</td>
            <td class="px-3 py-2 text-center font-extrabold">{{ (int)($tlum->score_com ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtW($wCom) }}</td>
            <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($tlum->pi_com ?? 0),2) }}</td>
          </tr>
        </tbody>

        <tfoot>
          <tr class="bg-yellow-200">
            <td colspan="6" class="px-3 py-3 font-black text-right text-slate-900">TOTAL</td>
            <td class="px-3 py-3 font-black text-right text-slate-900">
              {{ number_format((float)($tlum->pi_total ?? 0),2) }}
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
@endif

{{-- =========================
     TLUM INSIGHT PANEL
     (sudah oke, tinggal pastikan best/worst ada)
     ========================= --}}
@if(!empty($insight))
  @php
    $best = $insight->best ?? null;
    $worst = $insight->worst ?? null;
  @endphp

  <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">AO Terbaik (PI tertinggi)</div>
      @if($best)
        <div class="mt-1 font-extrabold text-slate-900">{{ $best->name }}</div>
        <div class="text-xs text-slate-500">AO: <b>{{ $best->ao_code }}</b></div>
        <div class="mt-2 text-sm">PI: <b>{{ number_format((float)$best->pi,2) }}</b></div>
        <div class="text-xs text-slate-500">RR {{ $fmtPct($best->rr) }} • OS {{ $fmtPct($best->os_pct) }} • NOA {{ (int)$best->noa }}</div>
      @else
        <div class="mt-2 text-slate-500 text-sm">-</div>
      @endif
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">AO Perlu Perhatian (PI terendah)</div>
      @if($worst)
        <div class="mt-1 font-extrabold text-slate-900">{{ $worst->name }}</div>
        <div class="text-xs text-slate-500">AO: <b>{{ $worst->ao_code }}</b></div>
        <div class="mt-2 text-sm">PI: <b>{{ number_format((float)$worst->pi,2) }}</b></div>
        <div class="text-xs text-slate-500">RR {{ $fmtPct($worst->rr) }} • OS {{ $fmtPct($worst->os_pct) }} • NOA {{ (int)$worst->noa }}</div>
      @else
        <div class="mt-2 text-slate-500 text-sm">-</div>
      @endif
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">Gap terhadap Target</div>
      <div class="mt-2 text-sm">NOA kurang: <b>{{ (int)($insight->noa_gap ?? 0) }}</b></div>
      <div class="text-sm">OS kurang: <b>{{ $fmtRp($insight->os_gap ?? 0) }}</b></div>
      <div class="text-sm">Community kurang: <b>{{ (int)($insight->community_gap ?? 0) }}</b></div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">Gap RR (Target - Actual)</div>
      @php $rrGap = (float)($insight->rr_gap ?? 0); @endphp
      <div class="mt-2 text-2xl font-extrabold {{ $rrGap <= 0 ? 'text-emerald-600' : 'text-rose-600' }}">
        {{ $rrGap <= 0 ? 'OK' : '+' . number_format($rrGap,2) . '%' }}
      </div>
      <div class="text-xs text-slate-500">Jika merah berarti RR scope di bawah target</div>
    </div>
  </div>
@endif

{{-- =========================
     TLUM RANKING AO UMKM
     Enhancement #1: risk highlight + filter
     Enhancement #2: click row -> scroll to AO card
     ========================= --}}
<div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden mb-6">
  <div class="px-5 py-4 border-b border-slate-200 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
    <div>
      <div class="text-lg font-extrabold text-slate-900">TLUM – Ranking AO UMKM</div>
      <div class="text-xs text-slate-500">
        Hanya menampilkan scheme <b>AO_UMKM</b> (skor 1–6).
        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200">
          {{ $accLabel }}
        </span>
      </div>
    </div>

    {{-- filter risk only --}}
    <label class="inline-flex items-center gap-2 text-sm text-slate-700">
      <input id="filterRiskOnly" type="checkbox" class="rounded border-slate-300">
      <span>Tampilkan RR < {{ number_format($rrRiskThreshold,0) }} saja</span>
    </label>
  </div>

  <div class="p-4 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-900 text-white">
        <tr>
          <th class="text-left px-3 py-2">#</th>
          <th class="text-left px-3 py-2">AO</th>
          <th class="text-right px-3 py-2">Total PI</th>
          <th class="text-right px-3 py-2">NOA</th>
          <th class="text-right px-3 py-2">OS %</th>
          <th class="text-right px-3 py-2">RR %</th>
          <th class="text-right px-3 py-2">Community</th>
          <th class="text-right px-3 py-2">Daily</th>
        </tr>
      </thead>

      <tbody class="divide-y divide-slate-200">
        @forelse(($tlumRows ?? collect()) as $i => $r)
          @php
            $rr = (float)($r->rr_pct ?? 0);
            $isRisk = $rr < $rrRiskThreshold;
            $br = $rrBadge($rr);

            // highlight row by risk
            $rowCls = $isRisk ? 'bg-rose-50 hover:bg-rose-100' : 'hover:bg-slate-50';

            // anchor to AO card
            $anchor = '#ao-'.(int)($r->user_id ?? 0);
          @endphp

          <tr
            data-rank-row="1"
            data-rr="{{ $rr }}"
            class="{{ $rowCls }} cursor-pointer"
            onclick="location.hash='{{ $anchor }}'"
            title="Klik untuk lompat ke detail AO"
          >
            <td class="px-3 py-2">{{ $i+1 }}</td>

            <td class="px-3 py-2">
              <div class="font-semibold text-slate-900">{{ $r->name }}</div>
              <div class="text-xs text-slate-500">AO Code: <b>{{ $r->ao_code }}</b></div>
            </td>

            <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($r->pi_total ?? 0),2) }}</td>
            <td class="px-3 py-2 text-right">{{ (int)($r->noa_disbursement ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ $fmtPct($r->os_disbursement_pct ?? 0) }}</td>

            <td class="px-3 py-2 text-right">
              {{ $fmtPct($rr) }}
              <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] {{ $br['cls'] }}">
                {{ $br['label'] }}
              </span>
            </td>

            <td class="px-3 py-2 text-right">{{ (int)($r->community_actual ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ (int)($r->daily_report_actual ?? 0) }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="8" class="px-3 py-6 text-center text-slate-500">
              Belum ada data TLUM (atau belum ada AO yang scheme=AO_UMKM pada periode ini).
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>

{{-- =========================
     AO DETAIL (card per AO)
     - Mobile: stacked KPI rows (tanpa scroll horizontal)
     - Desktop: table normal (7 kolom)
     ========================= --}}
<div class="space-y-6">
  @forelse($items as $it)
    @php
      $cardId = 'ao-'.(int)($it->user_id ?? 0);
      $rrAo = (float)($it->rr_pct ?? 0);
      $brAo = $rrBadge($rrAo);

      $wNoa = (float)($wUmkm['noa'] ?? 0.30);
      $wOs  = (float)($wUmkm['os'] ?? 0.20);
      $wRr  = (float)($wUmkm['rr'] ?? 0.25);
      $wCom = (float)($wUmkm['community'] ?? 0.20);
      $wDay = (float)($wUmkm['daily'] ?? 0.05);

      $fmtW = fn($w) => number_format($w * 100, 0) . '%';

      // helper kecil utk mobile rows
      $kv = function($label, $value) {
        return '<div class="text-[11px] text-slate-500">'.$label.'</div><div class="text-sm font-semibold text-slate-900">'.$value.'</div>';
      };
    @endphp

    <div id="{{ $cardId }}" class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden scroll-mt-24">

      {{-- HEADER (lebih compact & responsive) --}}
      <div class="px-4 sm:px-5 py-3 sm:py-4 border-b border-slate-200">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">

          <div class="min-w-0">
            <div class="flex items-start gap-3">
              <div class="min-w-0">
                <div class="text-base sm:text-lg font-black text-slate-900 uppercase truncate">
                  {{ $it->name }}
                </div>
                <div class="text-xs text-slate-500 mt-0.5">
                  {{ $it->level }} • AO Code: <b>{{ $it->ao_code ?: '-' }}</b>
                </div>
              </div>

              {{-- Total PI (mobile inline) --}}
              <div class="sm:hidden ml-auto text-right">
                <div class="text-[11px] text-slate-500">Total PI</div>
                <div class="text-xl font-black text-slate-900 leading-none">
                  {{ number_format((float)($it->pi_total ?? 0), 2) }}
                </div>
              </div>
            </div>

            {{-- badges --}}
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-emerald-100 text-emerald-700">
                AO UMKM (1–6)
              </span>

              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200">
                {{ $accLabel }}
              </span>

              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] {{ $brAo['cls'] }}">
                RR {{ $brAo['label'] }} • {{ number_format($rrAo,2) }}%
              </span>
            </div>
          </div>

          {{-- Total PI (desktop kanan) --}}
          <div class="hidden sm:block text-right">
            <div class="text-xs text-slate-500">Total PI</div>
            <div class="text-2xl font-black text-slate-900 leading-none">
              {{ number_format((float)($it->pi_total ?? 0), 2) }}
            </div>
          </div>

        </div>
      </div>

      {{-- =========================
           MOBILE VIEW (stacked KPI rows)
           ========================= --}}
      <div class="p-4 space-y-3 md:hidden">

        {{-- Row builder (tiap KPI jadi card kecil) --}}
        <div class="rounded-xl border border-slate-200 overflow-hidden">
          <div class="bg-orange-50 px-3 py-2 flex items-center justify-between">
            <div class="font-bold text-slate-900">Pertumbuhan NOA</div>
            <div class="text-xs text-slate-600">Bobot {{ $fmtW($wNoa) }}</div>
          </div>
          <div class="p-3 grid grid-cols-2 gap-3">
            {!! $kv('Target', (int)($it->target_noa_disbursement ?? 0)) !!}
            {!! $kv('Actual', (int)($it->noa_disbursement ?? 0)) !!}
            {!! $kv('Pencapaian', $fmtPct($it->noa_disbursement_pct ?? 0)) !!}
            {!! $kv('Skor', '<span class="font-black">'.(int)($it->score_noa ?? 0).'</span>') !!}
            {!! $kv('PI', '<span class="font-black">'.number_format((float)($it->pi_noa ?? 0),2).'</span>') !!}
            <div>
              <div class="text-[11px] text-slate-500">Catatan</div>
              <div class="text-sm text-slate-700">Akumulasi NOA (Jan–Periode)</div>
            </div>
          </div>
        </div>

        <div class="rounded-xl border border-slate-200 overflow-hidden">
          <div class="bg-orange-50 px-3 py-2 flex items-center justify-between">
            <div class="font-bold text-slate-900">Realisasi (Akumulasi)</div>
            <div class="text-xs text-slate-600">Bobot {{ $fmtW($wOs) }}</div>
          </div>
          <div class="p-3 grid grid-cols-2 gap-3">
            {!! $kv('Target', $fmtRp($it->target_os_disbursement ?? 0)) !!}
            {!! $kv('Actual', $fmtRp($it->os_disbursement ?? 0)) !!}
            {!! $kv('Pencapaian', $fmtPct($it->os_disbursement_pct ?? 0)) !!}
            {!! $kv('Skor', '<span class="font-black">'.(int)($it->score_os ?? 0).'</span>') !!}
            {!! $kv('PI', '<span class="font-black">'.number_format((float)($it->pi_os ?? 0),2).'</span>') !!}
            <div>
              <div class="text-[11px] text-slate-500">Catatan</div>
              <div class="text-sm text-slate-700">Disbursement akumulasi (Jan–Periode)</div>
            </div>
          </div>
        </div>

        <div class="rounded-xl border border-slate-200 overflow-hidden">
          <div class="bg-orange-50 px-3 py-2 flex items-center justify-between">
            <div class="font-bold text-slate-900">Kualitas Kredit (RR)</div>
            <div class="text-xs text-slate-600">Bobot {{ $fmtW($wRr) }}</div>
          </div>
          <div class="p-3 grid grid-cols-2 gap-3">
            {!! $kv('Target', number_format((float)($it->target_rr ?? 100),2).'%') !!}
            {!! $kv('Actual', number_format((float)($it->rr_pct ?? 0),2).'%') !!}
            {!! $kv('Pencapaian', $fmtPct($it->rr_pct ?? 0)) !!}
            {!! $kv('Skor', '<span class="font-black">'.(int)($it->score_rr ?? 0).'</span>') !!}
            {!! $kv('PI', '<span class="font-black">'.number_format((float)($it->pi_rr ?? 0),2).'</span>') !!}
            <div>
              <div class="text-[11px] text-slate-500">Status</div>
              <div class="text-sm font-semibold">{{ $brAo['label'] }}</div>
            </div>
          </div>
        </div>

        <div class="rounded-xl border border-slate-200 overflow-hidden">
          <div class="bg-orange-50 px-3 py-2 flex items-center justify-between">
            <div class="font-bold text-slate-900">Grab to Community</div>
            <div class="text-xs text-slate-600">Bobot {{ $fmtW($wCom) }}</div>
          </div>
          <div class="p-3 grid grid-cols-2 gap-3">
            {!! $kv('Target', (int)($it->target_community ?? 0)) !!}
            {!! $kv('Actual', (int)($it->community_actual ?? 0)) !!}
            {!! $kv('Pencapaian', $fmtPct($it->community_pct ?? 0)) !!}
            {!! $kv('Skor', '<span class="font-black">'.(int)($it->score_community ?? 0).'</span>') !!}
            {!! $kv('PI', '<span class="font-black">'.number_format((float)($it->pi_community ?? 0),2).'</span>') !!}
            <div>
              <div class="text-[11px] text-slate-500">Catatan</div>
              <div class="text-sm text-slate-700">Aktivitas komunitas</div>
            </div>
          </div>
        </div>

        <div class="rounded-xl border border-slate-200 overflow-hidden">
          <div class="bg-orange-50 px-3 py-2 flex items-center justify-between">
            <div class="font-bold text-slate-900">Daily Report (Kunjungan)</div>
            <div class="text-xs text-slate-600">Bobot {{ $fmtW($wDay) }}</div>
          </div>
          <div class="p-3 grid grid-cols-2 gap-3">
            {!! $kv('Target', (int)($it->target_daily_report ?? 0)) !!}
            {!! $kv('Actual', (int)($it->daily_report_actual ?? 0)) !!}
            {!! $kv('Pencapaian', $fmtPct($it->daily_report_pct ?? 0)) !!}
            {!! $kv('Skor', '<span class="font-black">'.(int)($it->score_daily_report ?? 0).'</span>') !!}
            {!! $kv('PI', '<span class="font-black">'.number_format((float)($it->pi_daily ?? 0),2).'</span>') !!}
            <div>
              <div class="text-[11px] text-slate-500">Catatan</div>
              <div class="text-sm text-slate-700">Kunjungan terlapor</div>
            </div>
          </div>
        </div>

        {{-- TOTAL --}}
        <div class="rounded-xl bg-yellow-200 px-4 py-3 flex items-center justify-between">
          <div class="font-black text-slate-900">TOTAL</div>
          <div class="text-2xl font-black text-slate-900">
            {{ number_format((float)($it->pi_total ?? 0),2) }}
          </div>
        </div>
      </div>

      {{-- =========================
           DESKTOP VIEW (table)
           ========================= --}}
      <div class="hidden md:block p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-orange-600 text-white">
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
            <tr>
              <td class="px-3 py-2 font-semibold">Pertumbuhan NOA</td>
              <td class="px-3 py-2 text-right">{{ (int)($it->target_noa_disbursement ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($it->noa_disbursement ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($it->noa_disbursement_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-center font-black">{{ (int)($it->score_noa ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtW($wNoa) }}</td>
              <td class="px-3 py-2 text-right font-black">{{ number_format((float)($it->pi_noa ?? 0),2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">Realisasi (Akumulasi)</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($it->target_os_disbursement ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($it->os_disbursement ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($it->os_disbursement_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-center font-black">{{ (int)($it->score_os ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtW($wOs) }}</td>
              <td class="px-3 py-2 text-right font-black">{{ number_format((float)($it->pi_os ?? 0),2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">Kualitas Kredit (RR)</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->target_rr ?? 100),2) }}%</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->rr_pct ?? 0),2) }}%</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($it->rr_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-center font-black">{{ (int)($it->score_rr ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtW($wRr) }}</td>
              <td class="px-3 py-2 text-right font-black">{{ number_format((float)($it->pi_rr ?? 0),2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">Grab to Community</td>
              <td class="px-3 py-2 text-right">{{ (int)($it->target_community ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($it->community_actual ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($it->community_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-center font-black">{{ (int)($it->score_community ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtW($wCom) }}</td>
              <td class="px-3 py-2 text-right font-black">{{ number_format((float)($it->pi_community ?? 0),2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">Daily Report (Kunjungan)</td>
              <td class="px-3 py-2 text-right">{{ (int)($it->target_daily_report ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($it->daily_report_actual ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($it->daily_report_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-center font-black">{{ (int)($it->score_daily_report ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtW($wDay) }}</td>
              <td class="px-3 py-2 text-right font-black">{{ number_format((float)($it->pi_daily ?? 0),2) }}</td>
            </tr>
          </tbody>

          <tfoot>
            <tr class="bg-yellow-200">
              <td colspan="6" class="px-3 py-3 font-black text-right text-slate-900">TOTAL</td>
              <td class="px-3 py-3 font-black text-right text-slate-900">
                {{ number_format((float)($it->pi_total ?? 0),2) }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

    </div>
  @empty
    <div class="rounded-2xl border border-slate-200 bg-white p-6 text-slate-600">
      Belum ada data KPI AO untuk periode ini. Jalankan <b>Recalc</b> untuk menghitung KPI.
    </div>
  @endforelse
</div>