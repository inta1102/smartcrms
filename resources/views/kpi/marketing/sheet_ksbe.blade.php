@extends('layouts.app')

@section('title', 'KPI KSBE – Sheet')

@section('content')
@php
  $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n) => number_format((float)($n ?? 0), 2) . '%';

  $badge = function($ach){
      $ach = (float)($ach ?? 0);
      if ($ach >= 100) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
      if ($ach >= 80)  return 'bg-amber-50 text-amber-700 border-amber-200';
      return 'bg-rose-50 text-rose-700 border-rose-200';
  };

  $wOs    = (float)($weights['os']    ?? 0.50);
  $wNoa   = (float)($weights['noa']   ?? 0.10);
  $wBunga = (float)($weights['bunga'] ?? 0.20);
  $wDenda = (float)($weights['denda'] ?? 0.20);

  $items = $items ?? collect();
  $recap = $recap ?? null;
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-6">

  {{-- HEADER --}}
  <div class="flex items-start justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">Periode</div>
      <div class="text-3xl font-black text-slate-900">{{ $period->format('F Y') }}</div>
      <div class="text-sm text-slate-600 mt-2">
        Leader: <b>{{ $leader['name'] ?? '-' }}</b> · Role: <b>{{ $leader['level'] ?? 'KSBE' }}</b>
        · Bawahan BE: <b>{{ $items->count() }}</b>
      </div>
    </div>

    <form method="GET" action="{{ route('kpi.marketing.sheet.ksbe') }}" class="flex items-center gap-2">
      <input type="month" name="period" value="{{ $period->format('Y-m') }}"
             class="rounded-xl border border-slate-200 px-3 py-2 text-sm" />
      <button class="rounded-xl bg-slate-900 text-white px-4 py-2 text-sm font-semibold">Tampilkan</button>
    </form>
  </div>

  {{-- RECAP KSBE --}}
  @if($recap)
    @php
      $piTotal = (float)($recap['pi']['total'] ?? 0);
    @endphp

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-4">
        <div>
          <div class="text-xl font-extrabold text-slate-900">REKAP KSBE (AGREGAT)</div>
          <div class="text-sm text-slate-600 mt-1">
            Scope: remedial · KPI agregat dari seluruh BE bawahan
          </div>
        </div>

        <div class="text-right">
          <div class="text-xs text-slate-500">Total PI</div>
          <div class="text-3xl font-extrabold text-slate-900">{{ number_format($piTotal, 2) }}</div>
          <div class="text-xs text-slate-500 mt-1">
            (OS {{ (int)round($wOs*100) }}% · NOA {{ (int)round($wNoa*100) }}% · Bunga {{ (int)round($wBunga*100) }}% · Denda {{ (int)round($wDenda*100) }}%)
          </div>
        </div>
      </div>

      {{-- mini cards --}}
      <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="rounded-2xl border border-slate-200 p-4">
          <div class="text-xs text-slate-500">Recovery OS</div>
          <div class="text-xl font-black text-slate-900">{{ $fmtRp($recap['actual']['os'] ?? 0) }}</div>
          <div class="text-xs text-slate-600 mt-1">
            Ach {{ $fmtPct($recap['ach']['os'] ?? 0) }} · Skor {{ (int)($recap['score']['os'] ?? 1) }}
          </div>
        </div>

        <div class="rounded-2xl border border-slate-200 p-4">
          <div class="text-xs text-slate-500">NOA Selesai</div>
          <div class="text-xl font-black text-slate-900">{{ (int)($recap['actual']['noa'] ?? 0) }}</div>
          <div class="text-xs text-slate-600 mt-1">
            Ach {{ $fmtPct($recap['ach']['noa'] ?? 0) }} · Skor {{ (int)($recap['score']['noa'] ?? 1) }}
          </div>
        </div>

        <div class="rounded-2xl border border-slate-200 p-4">
          <div class="text-xs text-slate-500">Bunga Masuk</div>
          <div class="text-xl font-black text-slate-900">{{ $fmtRp($recap['actual']['bunga'] ?? 0) }}</div>
          <div class="text-xs text-slate-600 mt-1">
            Ach {{ $fmtPct($recap['ach']['bunga'] ?? 0) }} · Skor {{ (int)($recap['score']['bunga'] ?? 1) }}
          </div>
        </div>

        <div class="rounded-2xl border border-slate-200 p-4">
          <div class="text-xs text-slate-500">Denda Masuk</div>
          <div class="text-xl font-black text-slate-900">{{ $fmtRp($recap['actual']['denda'] ?? 0) }}</div>
          <div class="text-xs text-slate-600 mt-1">
            Ach {{ $fmtPct($recap['ach']['denda'] ?? 0) }} · Skor {{ (int)($recap['score']['denda'] ?? 1) }}
          </div>
        </div>

        <div class="rounded-2xl border border-slate-200 p-4">
          <div class="text-xs text-slate-500">NPL Stock (Info)</div>
          <div class="text-sm text-slate-700 mt-1">
            Prev <b>{{ $fmtRp($recap['actual']['os_npl_prev'] ?? 0) }}</b><br>
            Now <b>{{ $fmtRp($recap['actual']['os_npl_now'] ?? 0) }}</b><br>
            Net drop <b>{{ $fmtRp($recap['actual']['net_npl_drop'] ?? 0) }}</b>
          </div>
        </div>
      </div>

      {{-- recap table --}}
      <div class="p-4 pt-0 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-indigo-600 text-white">
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
            @php
              $rows = [
                ['k'=>'OS Selesai (Recovery)','t'=>$recap['target']['os'] ?? 0,'a'=>$recap['actual']['os'] ?? 0,'ach'=>$recap['ach']['os'] ?? 0,'s'=>$recap['score']['os'] ?? 1,'w'=>$wOs,'pi'=>$recap['pi']['os'] ?? 0,'rp'=>true],
                ['k'=>'NOA Selesai','t'=>$recap['target']['noa'] ?? 0,'a'=>$recap['actual']['noa'] ?? 0,'ach'=>$recap['ach']['noa'] ?? 0,'s'=>$recap['score']['noa'] ?? 1,'w'=>$wNoa,'pi'=>$recap['pi']['noa'] ?? 0,'rp'=>false],
                ['k'=>'Bunga Masuk','t'=>$recap['target']['bunga'] ?? 0,'a'=>$recap['actual']['bunga'] ?? 0,'ach'=>$recap['ach']['bunga'] ?? 0,'s'=>$recap['score']['bunga'] ?? 1,'w'=>$wBunga,'pi'=>$recap['pi']['bunga'] ?? 0,'rp'=>true],
                ['k'=>'Denda Masuk','t'=>$recap['target']['denda'] ?? 0,'a'=>$recap['actual']['denda'] ?? 0,'ach'=>$recap['ach']['denda'] ?? 0,'s'=>$recap['score']['denda'] ?? 1,'w'=>$wDenda,'pi'=>$recap['pi']['denda'] ?? 0,'rp'=>true],
              ];
            @endphp

            @foreach($rows as $r)
              <tr>
                <td class="px-3 py-2 font-semibold">{{ $r['k'] }}</td>
                <td class="px-3 py-2 text-right">{{ $r['rp'] ? $fmtRp($r['t']) : (int)$r['t'] }}</td>
                <td class="px-3 py-2 text-right">{{ $r['rp'] ? $fmtRp($r['a']) : (int)$r['a'] }}</td>
                <td class="px-3 py-2 text-right">
                  <span class="inline-flex items-center px-2 py-1 rounded-full border text-xs font-semibold {{ $badge($r['ach']) }}">
                    {{ $fmtPct($r['ach']) }}
                  </span>
                </td>
                <td class="px-3 py-2 text-center font-black">{{ (int)$r['s'] }}</td>
                <td class="px-3 py-2 text-right">{{ (int)round($r['w']*100) }}%</td>
                <td class="px-3 py-2 text-right font-black">{{ number_format((float)$r['pi'], 2) }}</td>
              </tr>
            @endforeach
          </tbody>
          <tfoot>
            <tr class="bg-yellow-200">
              <td colspan="6" class="px-3 py-2 font-extrabold text-right">TOTAL</td>
              <td class="px-3 py-2 font-extrabold text-right">{{ number_format($piTotal, 2) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  @endif

  {{-- LIST BAWAHAN (reuse table BE loop kamu) --}}
  <div class="space-y-6">
    @if($items->isEmpty())
      <div class="rounded-2xl border border-slate-200 bg-white p-6 text-slate-600">
        Tidak ada bawahan BE pada periode ini (cek org_assignments / effective date / unit_code remedial).
      </div>
    @else
      @include('kpi.marketing._partials.sheet_be_cards', ['items'=>$items, 'weights'=>$weights])
    @endif
  </div>

</div>
@endsection