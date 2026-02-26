@extends('layouts.app')

@section('title', 'KPI KBL Sheet')

@section('content')
@php
  $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmt2  = fn($n) => number_format((float)($n ?? 0), 2, ',', '.');
  $fmtPct = fn($n,$d=2) => number_format((float)($n ?? 0), $d, ',', '.') . '%';

  $chipCls = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200';
  $pill = fn($txt,$cls='') => "inline-flex items-center rounded-full px-2.5 py-1 text-xs font-bold border $cls";

  $deltaBadge = function ($v, $suffix = 'p.p') {
      if ($v === null) return null;
      $x = (float)$v;
      if (abs($x) < 0.005) return ['0', 'bg-slate-50 text-slate-700 border-slate-200'];

      $sign = $x > 0 ? '+' : '';
      $cls  = $x > 0
          ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
          : 'bg-rose-50 text-rose-700 border-rose-200';

      return [$sign . number_format($x, 2, ',', '.') . $suffix, $cls];
  };
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-5">

  {{-- HEADER --}}
  <div class="flex items-start justify-between gap-4">
    <div>
      <div class="text-sm text-slate-500">Periode</div>
      <div class="text-4xl font-black text-slate-900">{{ $periodLabel }}</div>

      <div class="text-sm text-slate-600 mt-2 flex flex-wrap items-center gap-2">
        <span>Role: <b>KBL</b> · {{ $me->name ?? '-' }}</span>
        <span class="{{ $chipCls }}">Scope: {{ $meta['desc_count'] ?? 0 }} user</span>
        <span class="{{ $chipCls }}">Mode: <b class="ml-1">{{ $mode }}</b></span>
        <span class="{{ $chipCls }}">Source: aggregate KBL</span>

        @php
          [$stLabel,$stCls] = $scoreBadge($kblRow->total_score_weighted ?? 0);
        @endphp
        <span class="{{ $pill($stLabel, $stCls) }}">{{ $stLabel }}</span>

        @if(!$targetRow)
          <span class="{{ $pill('Target belum diisi', 'bg-amber-50 text-amber-800 border-amber-200') }}">Target belum diisi</span>
        @endif
      </div>
      
    </div>

    <div class="flex items-end gap-2">

      {{-- FORM TERAPKAN (GET) --}}
      <form method="GET" action="{{ route('kpi.kbl.sheet') }}" class="flex items-end gap-2">
        <div class="text-right">
          <div class="text-sm text-slate-500">Ganti periode</div>
          <input
            type="month"
            name="period"
            value="{{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}"
            class="rounded-xl border border-slate-200 px-3 py-2"
          />
        </div>

        <button type="submit" class="rounded-xl bg-slate-900 text-white px-4 py-2 font-semibold">
          Terapkan
        </button>
        @if(empty($targetRow))
          <a href="{{ route('kpi.kbl.target.edit', ['period' => \Carbon\Carbon::parse($periodYmd)->format('Y-m')]) }}"
            class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold border bg-amber-50 text-amber-800 border-amber-200">
            Target belum diisi
          </a>
        @else
          <a href="{{ route('kpi.kbl.target.edit', ['period' => \Carbon\Carbon::parse($periodYmd)->format('Y-m')]) }}"
            class="inline-flex items-center rounded-full px-3 py-1 text-xs font-bold border bg-slate-50 text-slate-700 border-slate-200">
            Edit Target
          </a>
        @endif
      </form>

      {{-- FORM RECALC (POST) --}}
      <form method="POST" action="{{ route('kpi.kbl.sheet.recalc') }}" class="inline-flex">
        @csrf
        <input type="hidden" name="period" value="{{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}">
        <button type="submit"
          class="rounded-xl border border-slate-200 bg-white px-4 py-2 font-semibold text-slate-700 hover:bg-slate-50">
          Recalc
        </button>
      </form>

    </div>
  </div>

  {{-- SUMMARY CARDS (5 KPI Kabag Lending) --}}

  @php
    // ===== helper delta text color + sign
    $deltaText = function ($v) {
        if (is_null($v)) return [null, 'text-slate-600', ''];
        $d = (float)$v;
        if ($d > 0)  return [$d, 'text-emerald-600', '+'];
        if ($d < 0)  return [$d, 'text-rose-600',  '']; // minus sudah ada
        return [$d, 'text-slate-600', ''];
    };

    $meta = is_string($kblRow->meta ?? null) ? json_decode($kblRow->meta, true) : [];
    $deltaKyd = $meta['delta']['kyd_pp'] ?? null;
    $deltaOs  = $meta['delta']['os'] ?? null;

    $dKyd = $deltaBadge($deltaKyd, ' p.p');
    
    // format sign + color
    $deltaSigned = function ($gap) {
        $v = (float)($gap ?? 0);
        if ($v > 0)  return ['+' . number_format($v, 2, ',', '.'), 'text-emerald-600'];
        if ($v < 0)  return [number_format($v, 2, ',', '.'), 'text-rose-600'];
        return ['0', 'text-slate-500'];
    };

    // untuk nilai uang
    $deltaRp = function ($gap) use ($fmtRp) {
        $v = (float)($gap ?? 0);
        $cls = $v > 0 ? 'text-emerald-600' : ($v < 0 ? 'text-rose-600' : 'text-slate-500');
        $sign = $v > 0 ? '+ ' : '';
        return [$sign . $fmtRp(abs($v)) , $cls, $v]; // abs biar enak dibaca
    };

    // untuk persen (pakai p.p)
    $deltaPp = function ($gap) use ($fmtPct) {
        $v = (float)($gap ?? 0);
        $cls = $v > 0 ? 'text-emerald-600' : ($v < 0 ? 'text-rose-600' : 'text-slate-500');
        $sign = $v > 0 ? '+ ' : '';
        return [$sign . number_format(abs($v), 2, ',', '.') . ' p.p', $cls, $v];
    };

    // untuk integer count
    $deltaInt = function ($gap) {
        $v = (int)($gap ?? 0);
        $cls = $v > 0 ? 'text-emerald-600' : ($v < 0 ? 'text-rose-600' : 'text-slate-500');
        $sign = $v > 0 ? '+ ' : '';
        return [$sign . number_format(abs($v), 0, ',', '.'), $cls, $v];
    };
  @endphp

  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">

    {{-- KYD --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="flex items-start justify-between gap-2">
        <div class="text-xs text-slate-500">Achievement KYD</div>

        @if($dKyd)
          <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $dKyd[1] }}">
            Δ {{ $dKyd[0] }}
          </span>
        @endif
      </div>

      <div class="mt-1 text-2xl font-black text-slate-900">
        {{ $fmtPct($kblRow->kyd_ach_pct ?? 0) }}
      </div>

      <div class="text-xs text-slate-500 mt-2">
        OS: <b>{{ $fmtRp($kblRow->os_actual ?? 0) }}</b><br>
        Target: <b>{{ $fmtRp($kblRow->os_target ?? 0) }}</b>

       @php
          $gapOs = (float)($kblRow->os_actual ?? 0) - (float)($kblRow->os_target ?? 0); // makin besar makin bagus
          [$gapOsTxt, $gapOsCls] = $deltaRp($gapOs);
        @endphp

        <div class="mt-1">
          <span class="text-[11px] text-slate-400">Δ (Actual-Target):</span>
          <b class="text-[11px] font-bold {{ $gapOsCls }}">{{ $gapOsTxt }}</b>
        </div>
      </div>

      @php [$lbl,$cls] = $scoreBadge($kblRow->score_kyd ?? 0); @endphp
      <div class="mt-2">
        <span class="{{ $pill($lbl, $cls) }}">{{ $lbl }}</span>
        <span class="text-xs text-slate-500 ml-2">Score: <b>{{ (int)($kblRow->score_kyd ?? 0) }}</b></span>
      </div>
    </div>

    {{-- =========================
        CARD: Migrasi DPK (DPK→NPL)
        KPI: migrasi_pct = mig_os / total_os (sudah kamu pakai)
        Target kecil (default fallback 2%)
        Delta: Target - Actual (karena makin kecil makin bagus)
      ========================= --}}
      @php
        $dpkActual = (float)($kblRow->dpk_mig_pct ?? 0);
        $dpkTarget = (float)($targetRow->target_dpk_mig_pct ?? 2); // fallback 2%
        $gapDpk    = $dpkTarget - $dpkActual; // ✅ makin kecil makin bagus
        [$gapDpkTxt, $gapDpkCls] = $deltaPp($gapDpk);
      @endphp

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Migrasi DPK (DPK→NPL)</div>

        <div class="mt-1 text-2xl font-black text-slate-900">
          {{ $fmtPct($dpkActual) }}
        </div>

        <div class="text-xs text-slate-500 mt-2 space-y-0.5">
          <div>Target: <b>{{ number_format($dpkTarget, 2, ',', '.') }}%</b></div>
          <div>
            <span class="text-[11px] text-slate-400">Δ (Target-Actual):</span>
            <b class="text-[11px] font-bold {{ $gapDpkCls }}">{{ $gapDpkTxt }}</b>
          </div>

          <div class="pt-1">
            Migrasi OS: <b>{{ $fmtRp($kblRow->dpk_to_npl_os ?? 0) }}</b><br>
            Migrasi NOA: <b>{{ (int)($kblRow->dpk_to_npl_noa ?? 0) }}</b><br>
            <span class="text-slate-400">Cohort base DPK OS:</span>
            <b>{{ $fmtRp($kblRow->dpk_base_os ?? 0) }}</b>
          </div>
        </div>

        @php [$lbl,$cls] = $scoreBadge($kblRow->score_dpk ?? 0); @endphp
        <div class="mt-2">
          <span class="{{ $pill($lbl, $cls) }}">{{ $lbl }}</span>
          <span class="text-xs text-slate-500 ml-2">Score: <b>{{ (int)($kblRow->score_dpk ?? 0) }}</b></span>
        </div>
      </div>


      {{-- =========================
        CARD: Achievement NPL
        Actual: npl_ratio_pct (makin kecil makin bagus)
        Target: npl_target_pct
        Delta: Target - Actual
      ========================= --}}
      @php
        $nplActual = (float)($kblRow->npl_ratio_pct ?? 0);
        $nplTarget = (float)($kblRow->npl_target_pct ?? 0);
        $gapNpl    = $nplTarget - $nplActual; // ✅ makin kecil makin bagus
        [$gapNplTxt, $gapNplCls] = $deltaPp($gapNpl);
      @endphp

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Achievement NPL</div>

        <div class="mt-1 text-2xl font-black text-slate-900">
          {{ $fmtPct($kblRow->npl_ach_pct ?? 0) }}
        </div>

        <div class="text-xs text-slate-500 mt-2 space-y-0.5">
          <div>NPL Ratio: <b>{{ $fmtPct($nplActual) }}</b></div>
          <div>Target: <b>{{ $fmtPct($nplTarget) }}</b></div>
          <div>
            <span class="text-[11px] text-slate-400">Δ (Target-Actual):</span>
            <b class="text-[11px] font-bold {{ $gapNplCls }}">{{ $gapNplTxt }}</b>
          </div>
        </div>

        @php [$lbl,$cls] = $scoreBadge($kblRow->score_npl ?? 0); @endphp
        <div class="mt-2">
          <span class="{{ $pill($lbl, $cls) }}">{{ $lbl }}</span>
          <span class="text-xs text-slate-500 ml-2">Score: <b>{{ (int)($kblRow->score_npl ?? 0) }}</b></span>
        </div>
      </div>


      {{-- =========================
        CARD: Pendapatan Bunga
        Delta: Actual - Target (makin besar makin bagus)
      ========================= --}}
      @php
        $intActual = (float)($kblRow->interest_actual ?? 0);
        $intTarget = (float)($kblRow->interest_target ?? 0);
        $gapInt    = $intActual - $intTarget; // ✅ makin besar makin bagus
        [$gapIntTxt, $gapIntCls] = $deltaRp($gapInt);
      @endphp

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Pendapatan Bunga</div>

        <div class="mt-1 text-2xl font-black text-slate-900">
          {{ $fmtPct($kblRow->interest_ach_pct ?? 0) }}
        </div>

        <div class="text-xs text-slate-500 mt-2 space-y-0.5">
          <div>Aktual: <b>{{ $fmtRp($intActual) }}</b></div>
          <div>Target: <b>{{ $fmtRp($intTarget) }}</b></div>
          <div>
            <span class="text-[11px] text-slate-400">Δ (Actual-Target):</span>
            <b class="text-[11px] font-bold {{ $gapIntCls }}">{{ $gapIntTxt }}</b>
          </div>
        </div>

        @php [$lbl,$cls] = $scoreBadge($kblRow->score_interest ?? 0); @endphp
        <div class="mt-2">
          <span class="{{ $pill($lbl, $cls) }}">{{ $lbl }}</span>
          <span class="text-xs text-slate-500 ml-2">Score: <b>{{ (int)($kblRow->score_interest ?? 0) }}</b></span>
        </div>
      </div>


      {{-- =========================
        CARD: Pemasaran Komunitas
        Delta: Actual - Target (makin besar makin bagus)
      ========================= --}}
      @php
        $comActual = (int)($kblRow->community_actual ?? 0);
        $comTarget = (int)($kblRow->community_target ?? 0);
        $gapCom    = $comActual - $comTarget; // ✅ makin besar makin bagus
        [$gapComTxt, $gapComCls] = $deltaInt($gapCom);

        $comPct = $comTarget > 0 ? ($comActual / $comTarget) * 100 : 0;
      @endphp

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Pemasaran Komunitas</div>

        <div class="mt-1 text-2xl font-black text-slate-900">{{ $comActual }}</div>

        <div class="text-xs text-slate-500 mt-2 space-y-0.5">
          <div>
            Target: <b>{{ $comTarget }}</b>
            <span class="ml-2">({{ $fmtPct($comPct) }})</span>
          </div>
          <div>
            <span class="text-[11px] text-slate-400">Δ (Actual-Target):</span>
            <b class="text-[11px] font-bold {{ $gapComCls }}">{{ $gapComTxt }}</b>
          </div>
        </div>

        @php [$lbl,$cls] = $scoreBadge($kblRow->score_community ?? 0); @endphp
        <div class="mt-2">
          <span class="{{ $pill($lbl, $cls) }}">{{ $lbl }}</span>
          <span class="text-xs text-slate-500 ml-2">Score: <b>{{ (int)($kblRow->score_community ?? 0) }}</b></span>
        </div>
      </div>
  </div>

  {{-- TOTAL SCORE CARD --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <div class="text-xs text-slate-500">Total Score Weighted</div>
        <div class="text-3xl font-extrabold text-slate-900 mt-1">{{ $fmt2($kblRow->total_score_weighted ?? 0) }}</div>
        <div class="text-xs text-slate-500 mt-2">
          Bobot: KYD 30% · DPK 15% · NPL 35% · Bunga 15% · Kom 5%
        </div>
      </div>

      <!-- <div class="text-xs text-slate-600">
        <div class="font-semibold text-slate-700 mb-1">Mini-check</div>
        <div>Scope AO: <b>{{ $meta['scope_ao_count'] ?? 0 }}</b></div>
        <div>Leaders: <b>{{ $meta['leaders_count'] ?? 0 }}</b></div>
        <div>Auto mode: <b>{{ $meta['auto_mode'] ?? '-' }}</b></div>
      </div> -->
    </div>
  </div>

  {{-- TABLES --}}
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    {{-- LEFT: SCOPE LEADERS --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
      <div class="p-4 border-b border-slate-100">
        <div class="flex items-start justify-between gap-3">
          <div>
            <div class="text-lg font-extrabold text-slate-900">Scope Leader (TLUM / KSLR / KSBE / KSFE)</div>
            <div class="text-sm text-slate-500">Stage 1: tampilkan list leader scope KBL. Nanti kita aktifkan drilldown ke sheet masing-masing.</div>
          </div>
          <span class="{{ $chipCls }}">Source: org_assignments</span>
        </div>
      </div>

      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr class="border-b border-slate-100">
            <th class="text-left px-4 py-3 w-14">No</th>
            <th class="text-left px-4 py-3">Nama</th>
            <th class="text-left px-4 py-3 w-24">Role</th>
            <th class="text-right px-4 py-3 w-28">AO Codes</th>
          </tr>
        </thead>
        <tbody>
          @forelse($leaderRows as $i => $r)
            <tr class="border-b border-slate-100 hover:bg-slate-50">
              <td class="px-4 py-3 font-semibold text-slate-700">{{ $i+1 }}</td>
              <td class="px-4 py-3">
                <div class="font-semibold text-slate-900">{{ $r->name }}</div>
                <div class="text-xs text-slate-500">User ID: {{ $r->id }}</div>
              </td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold border bg-slate-50 text-slate-700 border-slate-200">
                  {{ $r->role }}
                </span>
              </td>
              <td class="px-4 py-3 text-right text-slate-600">
                {{ $r->ao_code ? $r->ao_code : '—' }}
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="px-4 py-8 text-center text-slate-500">Tidak ada leader dalam scope.</td>
            </tr>
          @endforelse
        </tbody>
      </table>

      <!-- <div class="px-4 py-3 text-xs text-slate-500 border-t border-slate-100">
        Tip: tahap berikutnya kita bikin tombol <b>Detail</b> untuk drilldown ke TLUM/KSLR/KSBE/KSFE sheet.
      </div> -->
    </div>

    {{-- RIGHT: SOURCE PANEL (Audit) --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
      <div class="p-4 border-b border-slate-100">
        <div class="text-lg font-extrabold text-slate-900">Audit & Source</div>
        <div class="text-sm text-slate-500">Transparansi rumus & data source (biar Kabag percaya angka).</div>
      </div>

      <div class="p-4 space-y-3 text-sm text-slate-700">
        <div class="rounded-xl border border-slate-200 p-3">
          <div class="font-semibold">KYD</div>
          <div class="text-slate-600 text-xs mt-1">
            OS actual (sum outstanding) vs target_os (kpi_kbl_targets)
          </div>
        </div>

        <div class="rounded-xl border border-slate-200 p-3">
          <div class="font-semibold">Migrasi DPK</div>
          <div class="text-slate-600 text-xs mt-1">
            Baseline: snapshot prevMonth kolek=2 → Current: kolek≥3 (mode: {{ $mode }}).
            Numerator pakai prev.outstanding.
          </div>
        </div>

        <div class="rounded-xl border border-slate-200 p-3">
          <div class="font-semibold">NPL</div>
          <div class="text-slate-600 text-xs mt-1">
            NPL ratio: OS kolek≥3 / OS total. Achievement vs target_npl_pct.
          </div>
        </div>

        <div class="rounded-xl border border-slate-200 p-3">
          <div class="font-semibold">Pendapatan Bunga</div>
          <div class="text-slate-600 text-xs mt-1">
            Source: loan_installments.interest_paid pada bulan periode.
          </div>
        </div>

        <div class="rounded-xl border border-slate-200 p-3">
          <div class="font-semibold">Pemasaran Komunitas</div>
          <div class="text-slate-600 text-xs mt-1">
            Placeholder (sementara 0). Nanti kita sambungkan ke tabel komunitas / activity.
          </div>
        </div>
      </div>
    </div>

  </div>

</div>
@endsection