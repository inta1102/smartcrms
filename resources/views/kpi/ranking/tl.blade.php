@extends('layouts.app')

@section('title', 'Ranking KPI TL')

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-5">

  <div class="flex items-start justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">Periode</div>
      <div class="text-4xl font-black text-slate-900">{{ $periodLabel }}</div>
      <div class="text-sm text-slate-600 mt-2">
        Role kamu: <b>{{ strtoupper($meRole ?? 'TL') }}</b> · Total data: <b>{{ count($rows ?? []) }}</b>
      </div>
      <div class="text-xs text-slate-500 mt-1">
        Catatan: TL masih mode “listing” (sementara). Nanti kita sambungkan ke TL Sheet.
      </div>
    </div>

    <form method="GET" action="{{ route('kpi.ranking.tl') }}" class="flex items-end gap-2">
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
      <div class="text-xl font-bold text-slate-900">Ranking KPI TL</div>
      <div class="text-sm text-slate-500 mt-1">
        Klik nama untuk masuk TL Sheet (nanti kita arahkan).
      </div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-left text-slate-600">
            <th class="px-3 py-2">#</th>
            <th class="px-3 py-2">Nama</th>
            <th class="px-3 py-2">Level</th>
            <th class="px-3 py-2">Score</th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $r)
            @php
              $uid  = (int)($r['user_id'] ?? 0);
              $isMe = (int)(auth()->id() ?? 0) === $uid;
            @endphp
            <tr class="border-t {{ $isMe ? 'bg-amber-50' : '' }}">
              <td class="px-3 py-3 font-semibold">
                {{ $r['rank'] ?? '-' }}
                @if((int)($r['rank'] ?? 0) === 1) 🏆 @endif
              </td>
              <td class="px-3 py-3">
                {{-- sementara belum ada policy tl sheet, jadi tampilkan text dulu --}}
                <span class="font-semibold">{{ $r['name'] ?? '—' }}</span>
                @if($isMe)
                  <div class="text-xs text-amber-700">Ini kamu</div>
                @endif
              </td>
              <td class="px-3 py-3">{{ $r['level'] ?? 'TL' }}</td>
              <td class="px-3 py-3 font-black">
                @if(($r['meta']['score_source'] ?? '') === 'NO_MONTHLY_YET')
                    <span class="text-slate-400">—</span>
                @else
                    {{ number_format((float)$r['score'], 2, '.', '') }}
                @endif
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="4" class="px-3 py-6 text-center text-slate-500">
                Tidak ada data TL untuk periode ini.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection
