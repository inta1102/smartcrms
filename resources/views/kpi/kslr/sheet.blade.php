@extends('layouts.app')

@section('title', 'KPI KSLR Sheet')

@section('content')
@php
  // ==========================================================
  // HELPERS
  // ==========================================================
  $fmtPct = fn($n,$d=2) => number_format((float)($n ?? 0), $d, ',', '.') . '%';
  $fmt2   = fn($n) => number_format((float)($n ?? 0), 2, ',', '.');
  $fmt0   = fn($n) => number_format((float)($n ?? 0), 0, ',', '.');

  $fmtRp = function($n){
    $n = (float)($n ?? 0);
    return 'Rp ' . number_format($n, 0, ',', '.');
  };

  $chipCls = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200';

  // Score badge (1–6) => status
  $scoreBadge = function($s){
    $s = (float)($s ?? 0);
    if ($s >= 4.5) return ['Excellent', 'bg-emerald-100 text-emerald-800 border-emerald-200'];
    if ($s >= 3.5) return ['On Track',  'bg-emerald-50 text-emerald-800 border-emerald-200'];
    if ($s >= 2.5) return ['Warning',   'bg-amber-50 text-amber-800 border-amber-200'];
    if ($s >  0)   return ['Critical',  'bg-rose-50 text-rose-800 border-rose-200'];
    return ['N/A', 'bg-slate-50 text-slate-700 border-slate-200'];
  };

  // delta badge (MoM / DoD): positive/negative/flat
  $deltaBadge = function($deltaPct){
    if ($deltaPct === null || $deltaPct === '') return null;
    $d = (float)$deltaPct;
    if (abs($d) < 0.00001) return ['0,00%', 'bg-slate-50 text-slate-700 border-slate-200'];
    if ($d > 0) return [('+'.number_format($d,2,',','.')).'%', 'bg-emerald-50 text-emerald-800 border-emerald-200'];
    return [(number_format($d,2,',','.')).'%', 'bg-rose-50 text-rose-800 border-rose-200'];
  };

  // ==========================================================
  // WEIGHTS (tetap hard-coded sesuai desain)
  // ==========================================================
  $wKyd = 0.50; $wDpk = 0.15; $wRr = 0.25; $wKom = 0.10;

  // Optional: kalau komponen Kom belum ada, aman => 0
  $scoreKom = (float)($scoreKom ?? 0);

  // Breakdown weighted contributions (transparansi)
  $cKyd = (float)($scoreKyd ?? 0) * $wKyd;
  $cDpk = (float)($scoreDpk ?? 0) * $wDpk;
  $cRr  = (float)($scoreRr  ?? 0) * $wRr;
  $cKom = $scoreKom * $wKom;

  // Overall status based on totalScoreWeighted (atau fallback avg score)
  [$overallLabel, $overallCls] = $scoreBadge($totalScoreWeighted ?? 0);

  // Optional meta:
  // - $meta['scope_breakdown'] = ['TLRO'=>1,'TLSO'=>0,'SO'=>3,'RO'=>5] misalnya
  $scopeBreakdown = $meta['scope_breakdown'] ?? null;

  // Optional delta (kalau controller sudah ngisi):
  // $meta['mom'] = ['kyd'=>+1.2, 'dpk'=>-0.3, 'rr'=>+0.8, 'total'=>+0.2] dalam %
  $mom = $meta['mom'] ?? [];
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-5">

  {{-- ==========================================================
      HEADER
     ========================================================== --}}
  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
    <div class="min-w-0">
      <div class="text-sm text-slate-500">Periode</div>
      <div class="text-4xl font-black text-slate-900 truncate">{{ $periodLabel }}</div>

      <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-600">
        <div>Role: <b>KSLR</b> · <span class="font-semibold">{{ $me->name ?? '-' }}</span></div>

        <span class="{{ $chipCls }}">
          Scope: {{ $meta['desc_count'] ?? 0 }} user
        </span>

        <span class="{{ $chipCls }}">
          Status: <span class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $overallCls }}">
            {{ $overallLabel }}
          </span>
        </span>

        @if(is_array($scopeBreakdown) && count($scopeBreakdown))
          <span class="{{ $chipCls }}">
            Komposisi:
            @foreach($scopeBreakdown as $k => $v)
              <span class="ml-1 text-slate-700 font-semibold">{{ $k }} {{ (int)$v }}</span>
            @endforeach
          </span>
        @endif

        <span class="{{ $chipCls }}">
          Source: <span class="ml-1 font-semibold text-slate-700">aggregate KSLR</span>
        </span>
      </div>
    </div>

    <form class="flex items-end gap-2" method="GET" action="{{ route('kpi.kslr.sheet') }}">
      <div class="text-right">
        <div class="text-sm text-slate-500">Ganti periode</div>
        <input type="month" name="period" value="{{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}"
               class="rounded-xl border border-slate-200 px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-slate-200" />
      </div>
      <button class="rounded-xl bg-slate-900 text-white px-4 py-2 font-semibold hover:bg-slate-800">
        Terapkan
      </button>
    </form>
  </div>

  {{-- ==========================================================
      SUMMARY CARDS (Leadership-grade)
     ========================================================== --}}
  <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">

    {{-- TOTAL SCORE --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="flex items-start justify-between gap-2">
        <div class="text-xs text-slate-500">Total Score Weighted</div>
        @php $d = $deltaBadge($mom['total'] ?? null); @endphp
        @if($d)
          <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $d[1] }}">
            Δ {{ $d[0] }}
          </span>
        @endif
      </div>

      <div class="mt-1 flex items-end justify-between gap-3">
        <div class="text-3xl font-extrabold text-slate-900 leading-none">
          {{ $fmt2($totalScoreWeighted) }}
        </div>
        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold border {{ $overallCls }}">
          {{ $overallLabel }}
        </span>
      </div>

      {{-- Breakdown weighted --}}
      <div class="mt-3 text-xs text-slate-600 space-y-1">
        <div class="flex items-center justify-between">
          <span>KYD ({{ (int)($wKyd*100) }}%)</span>
          <span class="font-semibold">{{ $fmt2($scoreKyd) }} × {{ (int)($wKyd*100) }}% = {{ $fmt2($cKyd) }}</span>
        </div>
        <div class="flex items-center justify-between">
          <span>DPK ({{ (int)($wDpk*100) }}%)</span>
          <span class="font-semibold">{{ $fmt2($scoreDpk) }} × {{ (int)($wDpk*100) }}% = {{ $fmt2($cDpk) }}</span>
        </div>
        <div class="flex items-center justify-between">
          <span>RR ({{ (int)($wRr*100) }}%)</span>
          <span class="font-semibold">{{ $fmt2($scoreRr) }} × {{ (int)($wRr*100) }}% = {{ $fmt2($cRr) }}</span>
        </div>
        <div class="flex items-center justify-between">
          <span>Kom ({{ (int)($wKom*100) }}%)</span>
          <span class="font-semibold">{{ $fmt2($scoreKom) }} × {{ (int)($wKom*100) }}% = {{ $fmt2($cKom) }}</span>
        </div>

        <!-- <div class="pt-2 border-t border-slate-100 flex items-center justify-between">
          <span class="text-slate-500">Bobot</span>
          <span class="text-slate-700 font-semibold">KYD {{(int)($wKyd*100)}}% · DPK {{(int)($wDpk*100)}}% · RR {{(int)($wRr*100)}}% · Kom {{(int)($wKom*100)}}%</span>
        </div> -->
      </div>
    </div>

    {{-- KYD --}}
    @php [$kydLabel,$kydCls] = $scoreBadge($scoreKyd ?? 0); @endphp
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="flex items-start justify-between gap-2">
        <div class="text-xs text-slate-500">Achievement KYD</div>
        @php $d = $deltaBadge($mom['kyd'] ?? null); @endphp
        @if($d)
          <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $d[1] }}">Δ {{ $d[0] }}</span>
        @endif
      </div>

      <div class="mt-1 text-2xl font-black text-slate-900">
        {{ $fmtPct($kydAchPct) }}
      </div>

      <div class="mt-2 flex items-center justify-between text-xs text-slate-600">
        <div>Score: <b class="text-slate-900">{{ $fmt2($scoreKyd) }}</b></div>
        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $kydCls }}">{{ $kydLabel }}</span>
      </div>

      <div class="mt-2 text-[11px] text-slate-500">
        Source: target/ach KYD (scope)
      </div>
    </div>

    {{-- DPK --}}
    @php [$dpkLabel,$dpkCls] = $scoreBadge($scoreDpk ?? 0); @endphp
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="flex items-start justify-between gap-2">
        <div class="text-xs text-slate-500">Migrasi DPK</div>
        @php $d = $deltaBadge($mom['dpk'] ?? null); @endphp
        @if($d)
          <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $d[1] }}">Δ {{ $d[0] }}</span>
        @endif
      </div>

      <div class="mt-1 text-2xl font-black text-slate-900">
        {{ $fmtPct($dpkMigPct) }}
      </div>

      <div class="mt-2 flex items-center justify-between text-xs text-slate-600">
        <div>Score: <b class="text-slate-900">{{ $fmt2($scoreDpk) }}</b></div>
        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $dpkCls }}">{{ $dpkLabel }}</span>
      </div>

      <div class="mt-2 text-[11px] text-slate-500">
        Source: migrasi DPK (scope)
      </div>
    </div>

    {{-- RR --}}
    @php [$rrLabel,$rrCls] = $scoreBadge($scoreRr ?? 0); @endphp
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="flex items-start justify-between gap-2">
        <div class="text-xs text-slate-500">Repayment Rate</div>
        @php $d = $deltaBadge($mom['rr'] ?? null); @endphp
        @if($d)
          <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $d[1] }}">Δ {{ $d[0] }}</span>
        @endif
      </div>

      <div class="mt-1 text-2xl font-black text-slate-900">
        {{ $fmtPct($rrPct) }}
      </div>

      <div class="mt-2 flex items-center justify-between text-xs text-slate-600">
        <div>Score: <b class="text-slate-900">{{ $fmt2($scoreRr) }}</b></div>
        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $rrCls }}">{{ $rrLabel }}</span>
      </div>

      <div class="mt-2 text-[11px] text-slate-500">
        Source: repayment rate (scope)
      </div>
    </div>

  </div>

  {{-- ==========================================================
      TABLES
     ========================================================== --}}
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    {{-- LEFT: TL SCOPE --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-100">
        <div class="flex items-start justify-between gap-3">
        <div>
            <div class="flex items-center gap-2">
            <div class="text-lg font-extrabold text-slate-900">Scope TL (TLRO/TLSO)</div>
            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold border bg-slate-50 text-slate-700 border-slate-200">
                {{ count($tlRows ?? []) }} TL
            </span>
            </div>
            <div class="text-sm text-slate-500 mt-1">
            Stage 1: tampil TL + nilai KPI TLRO. Nanti kita aktifkan ranking TL (agregat SO/RO di bawahnya).
            </div>

            {{-- mini info (mode yg sedang dipakai + source) --}}
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-600">
            <span class="{{ $chipCls }}">
                Source: <span class="ml-1 font-semibold text-slate-700">org_assignments</span>
                <span class="mx-1 text-slate-400">+</span>
                <span class="font-semibold text-slate-700">kpi_tlro_monthlies</span>
            </span>

            @php
                $modeActive = null;
                if (!empty($tlRows) && is_array($tlRows)) {
                foreach ($tlRows as $t) { if (!empty($t->calc_mode)) { $modeActive = $t->calc_mode; break; } }
                }
            @endphp
            <span class="{{ $chipCls }}">
                Mode: <span class="ml-1 font-semibold text-slate-700">{{ $modeActive ?? 'auto' }}</span>
            </span>

            <span class="{{ $chipCls }}">
                Metric: <span class="ml-1 font-semibold text-slate-700">LI / PI / Stab / Risk / Imp</span>
            </span>
            </div>
        </div>
        </div>
    </div>

    {{-- wrapper scroll horizontal biar kolom banyak tetap enak --}}
    <div class="overflow-x-auto">
        <table class="min-w-[980px] w-full text-sm">
        <thead class="bg-slate-50 text-slate-600 sticky top-0 z-10">
            <tr onclick="window.location='{{ $r->href ?? '#' }}'" class="border-b border-slate-100">
            <th class="text-left px-4 py-3 w-14">No</th>
            <th class="text-left px-4 py-3 min-w-[220px]">Nama</th>
            <th class="text-left px-4 py-3 w-24">Role</th>

            {{-- KPI TLRO --}}
            <th class="text-right px-4 py-3 w-24">LI</th>
            <th class="text-right px-4 py-3 w-20">PI</th>
            <th class="text-right px-4 py-3 w-20">Stab</th>
            <th class="text-right px-4 py-3 w-20">Risk</th>
            <th class="text-right px-4 py-3 w-20">Imp</th>
            <th class="text-right px-4 py-3 w-16">RO</th>
            <th class="text-left px-4 py-3 w-28">Status</th>
            </tr>
        </thead>

        <tbody class="[&_*]:tabular-nums">
            @forelse($tlRows as $i => $r)
            @php
                $hasKpi = !is_null($r->leadership_index);
                [$liLabel,$liCls] = $scoreBadge($r->leadership_index ?? 0);

                // highlight row kalau KPI belum ada
                $rowCls = $hasKpi ? '' : 'bg-slate-50/60';
            @endphp

            <tr class="border-b border-slate-100 hover:bg-slate-50 {{ $rowCls }}">
                <td class="px-4 py-3 font-semibold text-slate-700">{{ $i+1 }}</td>

                <td class="px-4 py-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                    <div class="font-semibold text-slate-900 truncate">{{ $r->name }}</div>
                    <div class="text-xs text-slate-500">
                        User ID: {{ $r->id }}
                        @if(!empty($r->calc_mode))
                        · Mode: <span class="font-semibold">{{ $r->calc_mode }}</span>
                        @endif
                    </div>
                    </div>

                    {{-- optional: tombol drilldown (kalau nanti kamu supply $r->href) --}}
                    @if(!empty($r->href))
                    <a href="{{ $r->href }}"
                        class="shrink-0 inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        Detail
                    </a>
                    @endif
                </div>
                </td>

                <td class="px-4 py-3">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold border bg-slate-50 text-slate-700 border-slate-200">
                    {{ $r->role }}
                </span>
                </td>

                {{-- LI --}}
                <td class="px-4 py-3 text-right">
                @if(!$hasKpi)
                    <span class="text-xs text-slate-400">—</span>
                @else
                    <div class="font-black text-slate-900">{{ $fmt2($r->leadership_index) }}</div>
                    <div class="text-[11px] mt-1">
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 font-bold border {{ $liCls }}">
                        {{ $liLabel }}
                    </span>
                    </div>
                @endif
                </td>

                {{-- PI / Stability / Risk / Improvement --}}
                <td class="px-4 py-3 text-right text-slate-900">
                {{ $hasKpi ? $fmt2($r->pi_scope) : '—' }}
                </td>
                <td class="px-4 py-3 text-right text-slate-900">
                {{ $hasKpi ? $fmt2($r->stability_index) : '—' }}
                </td>
                <td class="px-4 py-3 text-right text-slate-900">
                {{ $hasKpi ? $fmt2($r->risk_index) : '—' }}
                </td>
                <td class="px-4 py-3 text-right text-slate-900">
                {{ $hasKpi ? $fmt2($r->improvement_index) : '—' }}
                </td>

                {{-- RO count --}}
                <td class="px-4 py-3 text-right text-slate-900">
                {{ $hasKpi ? (int)$r->ro_count : '—' }}
                </td>

                {{-- status_label --}}
                <td class="px-4 py-3">
                @if(!$hasKpi)
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold border bg-slate-50 text-slate-500 border-slate-200">
                    Belum ada KPI
                    </span>
                @else
                    {{-- kalau status_label null, pakai liLabel --}}
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold border bg-slate-50 text-slate-700 border-slate-200">
                    {{ $r->status_label ?? $liLabel }}
                    </span>
                @endif
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="10" class="px-4 py-10 text-center text-slate-500">
                Tidak ada TL dalam scope.
                </td>
            </tr>
            @endforelse
        </tbody>
        </table>
    </div>

    {{-- footer hint --}}
    <div class="p-3 border-t border-slate-100 text-xs text-slate-500">
        Tip: kalau kolom banyak, scroll ke kanan. Nanti bisa kita tambahkan “ranking TL” dan drilldown ke TLRO sheet.
    </div>
    </div>

    {{-- RIGHT: SO RANKING --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
      <div class="p-4 border-b border-slate-100">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="text-lg font-extrabold text-slate-900">Ranking SO</div>
            <div class="text-sm text-slate-500">Source: <b>kpi_so_monthlies</b> (period)</div>
          </div>
          <div class="flex items-center gap-2">
            <input id="soSearch" type="text" placeholder="Cari SO..."
                   class="w-40 rounded-xl border border-slate-200 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-slate-200" />
            <select id="soSort" class="rounded-xl border border-slate-200 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-slate-200">
              <option value="rank_asc">Sort: Rank</option>
              <option value="score_desc">Score ↓</option>
              <option value="os_desc">OS ↓</option>
              <option value="rr_desc">RR ↓</option>
              <option value="act_desc">Act ↓</option>
            </select>
          </div>
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm" id="soTable">
          <thead class="bg-slate-50 text-slate-600">
            <tr class="border-b border-slate-100">
              <th class="text-left px-4 py-3 w-14">Rank</th>
              <th class="text-left px-4 py-3">SO</th>
              <th class="text-right px-4 py-3 w-32">OS</th>
              <th class="text-right px-4 py-3 w-24">RR</th>
              <th class="text-right px-4 py-3 w-16">Act</th>
              <th class="text-right px-4 py-3 w-24">Score</th>
            </tr>
          </thead>
          <tbody>
            @forelse($soRank as $i => $r)
              @php
                // status berdasarkan score SO
                [$sLabel,$sCls] = $scoreBadge($r->score ?? 0);

                // optional: row click ke detail (kalau route ada)
                // contoh: route('kpi.so.sheet', ['user'=>$r->user_id,'period'=>$periodYmd])
                $rowHref = $r->href ?? null; // biar controller boleh supply
              @endphp

              <tr class="border-b border-slate-100 hover:bg-slate-50 cursor-pointer so-row"
                  data-name="{{ mb_strtolower($r->name ?? '') }}"
                  data-rank="{{ $i+1 }}"
                  data-score="{{ (float)($r->score ?? 0) }}"
                  data-os="{{ (float)($r->os ?? 0) }}"
                  data-rr="{{ (float)($r->rr ?? 0) }}"
                  data-act="{{ (int)($r->activity ?? 0) }}"
                  @if($rowHref) data-href="{{ $rowHref }}" @endif
              >
                <td class="px-4 py-3 font-semibold text-slate-700">{{ $i+1 }}</td>

                <td class="px-4 py-3">
                  <div class="flex items-center justify-between gap-2">
                    <div class="min-w-0">
                      <div class="font-semibold text-slate-900 truncate">{{ $r->name }}</div>
                      <div class="text-xs text-slate-500">Status:
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $sCls }}">{{ $sLabel }}</span>
                      </div>
                    </div>
                  </div>
                </td>

                <td class="px-4 py-3 text-right font-semibold text-slate-900">{{ $fmt0($r->os) }}</td>
                <td class="px-4 py-3 text-right text-slate-900">{{ $fmtPct($r->rr) }}</td>
                <td class="px-4 py-3 text-right text-slate-900">{{ (int)($r->activity ?? 0) }}</td>
                <td class="px-4 py-3 text-right font-black text-slate-900">{{ $fmt2($r->score) }}</td>
              </tr>
            @empty
              <tr>
                <td colspan="6" class="px-4 py-8 text-center text-slate-500">
                  Tidak ada data KPI SO.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      {{-- Footer hint --}}
      <div class="p-3 text-xs text-slate-500 border-t border-slate-100">
        Tip: klik baris untuk drill-down (jika link detail disediakan).
      </div>
    </div>

  </div>

</div>

{{-- ==========================================================
    JS: Search + Sort + Row Click (ringan)
   ========================================================== --}}
<script>
(function(){
  const q = document.getElementById('soSearch');
  const sel = document.getElementById('soSort');
  const table = document.getElementById('soTable');
  if (!table) return;

  const tbody = table.querySelector('tbody');
  const rows = Array.from(tbody.querySelectorAll('tr.so-row'));

  function applyFilter(){
    const needle = (q?.value || '').trim().toLowerCase();
    rows.forEach(r => {
      const name = r.getAttribute('data-name') || '';
      const ok = !needle || name.includes(needle);
      r.style.display = ok ? '' : 'none';
    });
  }

  function applySort(){
    const mode = sel?.value || 'rank_asc';

    const visible = rows.filter(r => r.style.display !== 'none');
    const hidden  = rows.filter(r => r.style.display === 'none');

    const getNum = (r, key) => Number(r.getAttribute(key) || 0);

    visible.sort((a,b)=>{
      if (mode === 'rank_asc')  return getNum(a,'data-rank') - getNum(b,'data-rank');
      if (mode === 'score_desc')return getNum(b,'data-score') - getNum(a,'data-score');
      if (mode === 'os_desc')   return getNum(b,'data-os') - getNum(a,'data-os');
      if (mode === 'rr_desc')   return getNum(b,'data-rr') - getNum(a,'data-rr');
      if (mode === 'act_desc')  return getNum(b,'data-act') - getNum(a,'data-act');
      return 0;
    });

    // re-append in order (visible then hidden keep at bottom hidden)
    [...visible, ...hidden].forEach(r => tbody.appendChild(r));
  }

  if (q) q.addEventListener('input', ()=>{ applyFilter(); applySort(); });
  if (sel) sel.addEventListener('change', applySort);

  // Row click drilldown
  rows.forEach(r=>{
    r.addEventListener('click', ()=>{
      const href = r.getAttribute('data-href');
      if (href) window.location.href = href;
    });
  });

  // init
  applyFilter();
  applySort();
})();
</script>
@endsection