@extends('layouts.app')

@section('title', 'Ranking KPI AO')

@section('content')
@php
  $meId = (int)(auth()->id() ?? 0);

  $rrBadge = function($rr) {
    $rr = (float)($rr ?? 0);
    if ($rr >= 90) return ['label'=>'AMAN', 'cls'=>'bg-emerald-50 text-emerald-700 border-emerald-200'];
    if ($rr >= 80) return ['label'=>'WASPADA', 'cls'=>'bg-amber-50 text-amber-700 border-amber-200'];
    return ['label'=>'RISIKO', 'cls'=>'bg-rose-50 text-rose-700 border-rose-200'];
  };
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-5">

  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
    <div>
      <div class="text-xs text-slate-500">Periode</div>
      <div class="text-3xl font-extrabold text-slate-900">{{ $periodLabel ?? '-' }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Role kamu: <b>{{ $meRole ?? '-' }}</b> · Total data: <b>{{ is_array($rows ?? null) ? count($rows) : 0 }}</b>
      </div>
      <div class="text-xs text-slate-400 mt-1">
        Ranking diurutkan berdasarkan <b>score_total</b> (tie-break: RR%, score_kolek).
      </div>
    </div>

    <form method="GET" class="flex items-end gap-2">
      <div class="space-y-1">
        <div class="text-xs text-slate-500">Ganti periode</div>
        @php $periodMonth = \Carbon\Carbon::parse($periodYmd)->format('Y-m'); @endphp

        <input
          type="month"
          name="period"
          value="{{ $periodMonth }}"
          class="border border-slate-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-slate-300"
        />

      </div>
      <button class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm hover:bg-slate-800">
        Terapkan
      </button>
    </form>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
    <div class="p-4 border-b border-slate-200 flex items-center justify-between">
      <div>
        <div class="text-base font-semibold text-slate-900">Ranking KPI AO</div>
        <div class="text-xs text-slate-500 mt-1">Klik nama AO untuk melihat detail KPI (breakdown).</div>
      </div>
      <div class="text-xs text-slate-500">
        @if(!empty($rows)) Menampilkan <b>{{ count($rows) }}</b> baris @endif
      </div>
    </div>

    <div class="p-3 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr>
            <th class="text-left p-2 w-12">#</th>
            <th class="text-left p-2">AO</th>
            <th class="text-left p-2 w-28">AO Code</th>
            <th class="text-right p-2 w-28">Total PI</th>
            <th class="text-right p-2 w-24">RR</th>
            <th class="text-left p-2 w-28">Risk</th>
          </tr>
        </thead>

        <tbody class="text-slate-800">
          @forelse($rows ?? [] as $r)
            @php
              $isMe = ((int)($r['user_id'] ?? 0) === $meId);
              $rank = (int)($r['rank'] ?? 0);

              $rr = (float)($r['meta']['rr_pct'] ?? 0);
              $b = $rrBadge($rr);

              $badge = null;
              if ($rank === 1) $badge = '🏆';
              elseif ($rank === 2) $badge = '🥈';
              elseif ($rank === 3) $badge = '🥉';
            @endphp

            <tr class="border-t {{ $isMe ? 'bg-amber-50' : 'hover:bg-slate-50' }}">
              <td class="p-2 font-semibold text-slate-700">
                <span class="inline-flex items-center gap-1">
                  {{ $rank }}
                  @if($badge) <span class="text-base">{{ $badge }}</span> @endif
                </span>
              </td>

              <td class="p-2">
                @php
                  $canView = \Illuminate\Support\Facades\Gate::allows('kpi-ao-view', \App\Models\User::find($r['user_id']));
                @endphp

                @if($canView)
                  <a href="{{ route('kpi.ao.show', $r['user_id']) }}?period={{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}"
                    class="font-semibold text-slate-900 hover:underline">
                    {{ $r['name'] }}
                  </a>
                @else
                  <span class="font-semibold text-slate-500 cursor-not-allowed" title="Tidak punya akses melihat detail">
                    {{ $r['name'] }}
                  </span>
                @endif

                @if($isMe)
                  <div class="text-xs text-amber-700 mt-0.5">Ini kamu</div>
                @endif
              </td>

              <td class="p-2 font-mono text-slate-700">{{ $r['code'] ?? '' }}</td>

              <td class="p-2 text-right font-extrabold text-slate-900">
                {{ number_format((float)($r['score'] ?? 0), 2) }}
              </td>

              <td class="p-2 text-right text-slate-700">
                {{ number_format($rr, 2) }}%
              </td>

              <td class="p-2">
                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs border {{ $b['cls'] }}">
                  {{ $b['label'] }}
                </span>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="6" class="p-6 text-slate-500">
                Tidak ada data KPI AO untuk periode ini.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection
