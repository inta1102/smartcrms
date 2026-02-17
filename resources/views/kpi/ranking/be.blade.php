@extends('layouts.app')

@section('title', 'Ranking KPI BE')

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-5">

  <div class="flex items-start justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">Periode</div>
      <div class="text-4xl font-black text-slate-900">{{ $periodLabel }}</div>
      <div class="text-sm text-slate-600 mt-2">
        Role kamu: <b>{{ strtoupper($meRole ?? 'BE') }}</b> · Total data: <b>{{ count($rows ?? []) }}</b>
      </div>
      <div class="text-xs text-slate-500 mt-1">
        Urutan: <b>Total PI</b> → Score OS → Score NOA → Score Bunga → Score Denda.
      </div>
    </div>

    <form method="GET" action="{{ route('kpi.ranking.be') }}" class="flex items-end gap-2">
      <div>
        <div class="text-sm text-slate-600 mb-1">Ganti periode</div>
        <input type="month" name="period"
          value="{{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}"
          class="rounded-xl border border-slate-200 px-3 py-2">
      </div>
      <button class="rounded-xl bg-slate-900 text-white px-4 py-2 font-semibold">
        Terapkan
      </button>
    </form>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="text-xl font-bold text-slate-900">Ranking KPI BE</div>
      <div class="text-sm text-slate-500 mt-1">Klik nama untuk drilldown ke BE Sheet.</div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-left text-slate-600">
            <th class="px-3 py-2">#</th>
            <th class="px-3 py-2">Nama</th>
            <th class="px-3 py-2">Total PI</th>
            <th class="px-3 py-2">OS Selesai</th>
            <th class="px-3 py-2">NOA Selesai</th>
            <th class="px-3 py-2">Bunga</th>
            <th class="px-3 py-2">Denda</th>
            <th class="px-3 py-2">Net NPL Turun</th>
            <th class="px-3 py-2">NPL</th>
          </tr>
        </thead>

        <tbody>
          @forelse($rows as $r)
            @php
              $uid  = (int)($r['user_id'] ?? 0);
              $isMe = (int)(auth()->id() ?? 0) === $uid;

              $m = $r['meta'] ?? [];

              $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
              $fmtNum = fn($n) => number_format((float)($n ?? 0), 0, ',', '.');

              $totalPi = (float)($m['total_pi'] ?? ($r['score'] ?? 0));

              $status = strtoupper((string)($m['status'] ?? 'DRAFT'));
              $can = !empty($allowedUserIds[$uid]);
            @endphp

            <tr class="border-t {{ $isMe ? 'bg-amber-50' : '' }}">
              <td class="px-3 py-3 font-semibold">
                {{ $r['rank'] ?? '-' }}
                @if((int)($r['rank'] ?? 0) === 1) 🏆 @endif
              </td>

              <td class="px-3 py-3">
                @if($can)
                  <a href="{{ route('kpi.be.show', ['beUserId' => $uid, 'period' => $periodYmd]) }}"
                     class="font-semibold hover:underline">
                    {{ $r['name'] ?? '—' }}
                  </a>
                @else
                  <span class="text-slate-400 font-semibold cursor-not-allowed">
                    {{ $r['name'] ?? '—' }}
                  </span>
                @endif

                @if($isMe)
                  <div class="text-xs text-amber-700">Ini kamu</div>
                @endif
              </td>

              <td class="px-3 py-3 font-black text-slate-900">
                {{ number_format($totalPi, 2, '.', '') }}
              </td>

              <td class="px-3 py-3">
                <div>{{ $fmtRp($m['actual_os_selesai'] ?? 0) }}</div>
                <div class="text-xs text-slate-500">T: {{ $fmtRp($m['target_os_selesai'] ?? 0) }}</div>
              </td>

              <td class="px-3 py-3">
                <div>{{ $fmtNum($m['actual_noa_selesai'] ?? 0) }}</div>
                <div class="text-xs text-slate-500">T: {{ $fmtNum($m['target_noa_selesai'] ?? 0) }}</div>
              </td>

              <td class="px-3 py-3">
                <div>{{ $fmtRp($m['actual_bunga_masuk'] ?? 0) }}</div>
                <div class="text-xs text-slate-500">T: {{ $fmtRp($m['target_bunga_masuk'] ?? 0) }}</div>
              </td>

              <td class="px-3 py-3">
                <div>{{ $fmtRp($m['actual_denda_masuk'] ?? 0) }}</div>
                <div class="text-xs text-slate-500">T: {{ $fmtRp($m['target_denda_masuk'] ?? 0) }}</div>
              </td>

              <td class="px-3 py-3">
                @php
                  $npl = (float)($m['net_npl_drop'] ?? 0);
                @endphp

                <span class="
                  {{ $npl > 0 ? 'text-emerald-600 font-semibold' : '' }}
                  {{ $npl < 0 ? 'text-rose-600 font-semibold' : '' }}
                ">
                  {{ $fmtRp($npl) }}
                </span>

              </td>

              <td class="px-3 py-3">
                @php
                    $npl = (float)($m['net_npl_drop'] ?? 0);
                @endphp

                @if($npl > 0)
                    <span class="px-3 py-1 rounded-full text-xs font-semibold 
                                bg-emerald-100 text-emerald-700 border border-emerald-200">
                        ↓ Turun
                    </span>
                @elseif($npl < 0)
                    <span class="px-3 py-1 rounded-full text-xs font-semibold 
                                bg-rose-100 text-rose-700 border border-rose-200">
                        ↑ Naik
                    </span>
                @else
                    <span class="px-3 py-1 rounded-full text-xs font-semibold 
                                bg-slate-100 text-slate-600 border border-slate-200">
                        → Stabil
                    </span>
                @endif
            </td>

            </tr>

          @empty
            <tr>
              <td colspan="9" class="px-3 py-6 text-center text-slate-500">
                Tidak ada data BE untuk periode ini.
              </td>
            </tr>
          @endforelse
        </tbody>

      </table>
    </div>
  </div>

</div>
@endsection
