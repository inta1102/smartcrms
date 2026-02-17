@extends('layouts.app')

@section('title', 'Ranking KPI SO')

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-4">

  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">Periode</div>
      <div class="text-4xl font-extrabold text-slate-900">{{ $periodLabel }}</div>
      <div class="text-sm text-slate-500 mt-1">
        Role kamu: <b>{{ $meRole }}</b> • Total data: <b>{{ count($rows) }}</b>
      </div>
      <div class="text-xs text-slate-400 mt-1">
        Urutan: <b>Score</b> → RR% → OS Disbursement → NOA Disbursement → Activity%.
      </div>
    </div>

    <form method="GET" action="{{ route('kpi.ranking.so') }}" class="flex items-end gap-2">
      <div>
        <div class="text-sm text-slate-600">Ganti periode</div>
        <input type="month" name="period"
               value="{{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}"
               class="mt-1 w-44 rounded-xl border border-slate-200 px-3 py-2">
      </div>
      <button class="rounded-xl bg-slate-900 text-white px-4 py-2 hover:bg-slate-800">
        Terapkan
      </button>
    </form>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-200">
      <div class="text-xl font-extrabold text-slate-900">Ranking KPI SO</div>
      <div class="text-sm text-slate-500">Klik nama untuk drilldown ke SO Sheet.</div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr>
            <th class="text-left px-3 py-2">#</th>
            <th class="text-left px-3 py-2">Nama</th>
            <th class="text-left px-3 py-2">AO Code</th>
            <th class="text-right px-3 py-2">Score</th>
            <th class="text-right px-3 py-2">RR %</th>
            <th class="text-right px-3 py-2">OS Disb</th>
            <th class="text-right px-3 py-2">NOA</th>
            <th class="text-right px-3 py-2">Komunitas</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse($rows as $r)
            @php
              $uid = (int)($r['user_id'] ?? 0);
              $me  = (int)auth()->id();
              $isMe = $uid === $me;

              $m = $r['meta'] ?? [];

              // self always allowed
              $canView = $isMe || !empty($allowedUserIds[$uid] ?? false);
            @endphp

            <tr class="{{ $isMe ? 'bg-yellow-50' : 'hover:bg-slate-50' }}">
              <td class="px-3 py-2">
                <div class="flex items-center gap-2">
                  <span class="font-semibold">{{ $r['rank'] }}</span>
                  @if($r['rank'] === 1) <span title="Top 1">🏆</span> @endif
                </div>
              </td>

              <td class="px-3 py-2">
                @if($canView && \Illuminate\Support\Facades\Route::has('kpi.so.show'))
                  <a href="{{ route('kpi.so.show', $uid) }}?period={{ \Carbon\Carbon::parse($periodYmd)->format('Y-m') }}">
                    {{ $r['name'] }}
                  </a>
                @else
                  <span class="font-semibold text-slate-500 cursor-not-allowed"
                        title="Tidak punya akses melihat detail">
                    {{ $r['name'] }}
                  </span>
                @endif

                @if($isMe) <div class="text-xs text-amber-700">Ini kamu</div> @endif
              </td>

              <td class="px-3 py-2 font-mono">{{ $r['code'] ?: '-' }}</td>

              <td class="px-3 py-2 text-right font-extrabold">
                {{ number_format((float)($r['score'] ?? 0), 2) }}
              </td>

              <td class="px-3 py-2 text-right">
                {{ number_format((float)($m['rr_pct'] ?? 0), 2) }}%
              </td>

              <td class="px-3 py-2 text-right">
                Rp {{ number_format((int)($m['os_disbursement'] ?? 0), 0, ',', '.') }}
              </td>

              <td class="px-3 py-2 text-right">
                {{ (int)($m['noa_disbursement'] ?? 0) }}
              </td>

              <td class="px-3 py-2 text-right">
                {{ number_format((float)($m['activity_pct'] ?? 0),2) }}%
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="8" class="px-3 py-6 text-center text-slate-500">
                Belum ada data KPI SO untuk periode ini.
              </td>
            </tr>
          @endforelse
        </tbody>

      </table>
    </div>
  </div>

</div>
@endsection
