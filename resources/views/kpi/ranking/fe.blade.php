@extends('layouts.app')

@section('title', 'Ranking KPI FE')

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-5">

  <div class="flex items-start justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">Periode</div>
      <div class="text-4xl font-black text-slate-900">{{ $periodLabel }}</div>
      <div class="text-sm text-slate-600 mt-2">
        Role kamu: <b>FE</b> · Total data: <b>{{ count($rows ?? []) }}</b>
      </div>
      <div class="text-xs text-slate-500 mt-1">
        Urutan: <b>Score</b> → OS Turun → Migrasi → Penalty.
      </div>
    </div>

    <form method="GET" action="{{ route('kpi.ranking.fe') }}" class="flex items-end gap-2">
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
      <div class="text-xl font-bold text-slate-900">Ranking KPI FE
        <!-- @php
            $meId = (int)(auth()->id() ?? 0);

            // cari row saya dari collection
            $myRow = null;
            if ($rows instanceof \Illuminate\Support\Collection) {
                $myRow = $rows->firstWhere('fe_user_id', $meId);
            } else {
                foreach (($rows ?? []) as $tmp) {
                    if ((int)($tmp->fe_user_id ?? 0) === $meId) { $myRow = $tmp; break; }
                }
            }

            $s = (float)($myRow->total_score_weighted ?? 0);

            $status = $s >= 4 ? ['On Track','bg-emerald-100 text-emerald-800 border-emerald-200']
                    : ($s >= 3 ? ['Warning','bg-amber-100 text-amber-800 border-amber-200']
                              : ['Critical','bg-rose-100 text-rose-800 border-rose-200']);
          @endphp

          @if($myRow)
            <span class="text-xs px-2 py-1 rounded-full border {{ $status[1] }}">{{ $status[0] }}</span>
          @else
            <span class="text-xs px-2 py-1 rounded-full border bg-slate-100 text-slate-700 border-slate-200">N/A</span>
          @endif -->
      </div>

      <div class="text-sm text-slate-500 mt-1">Klik nama untuk drilldown ke FE Sheet.</div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-left text-slate-600">
            <th class="px-3 py-2">#</th>
            <th class="px-3 py-2">Nama</th>
            <th class="px-3 py-2">AO Code</th>
            <th class="px-3 py-2">Score</th>
            <th class="px-3 py-2">OS Turun</th>
            <th class="px-3 py-2">Migrasi %</th>
            <th class="px-3 py-2">Penalty</th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $r)
            @php
              $isMe = (int)(auth()->id() ?? 0) === (int)$r->fe_user_id;
              $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
              $fmtPct = fn($n) => number_format((float)($n ?? 0), 2, ',', '.') . '%';
            @endphp
            <tr class="border-t {{ $isMe ? 'bg-amber-50' : '' }}">
              <td class="px-3 py-3 font-semibold">
                {{ $r->rank }}
                @if($r->rank == 1) 🏆 @endif
              </td>
              <td class="px-3 py-3">
                @php $can = !empty($allowedUserIds[(int)$r->fe_user_id]); @endphp

                  @if($can)
                    <a href="{{ route('kpi.fe.show', ['feUserId' => $r->fe_user_id, 'period' => $periodYmd]) }}" class="font-semibold hover:underline">
                      {{ $r->name }}
                    </a>
                  @else
                    <span class="text-slate-400 font-semibold cursor-not-allowed">{{ $r->name }}</span>
                  @endif

                @if($isMe)
                  <div class="text-xs text-amber-700">Ini kamu</div>
                @endif
              </td>
              <td class="px-3 py-3 font-mono">{{ $r->ao_code }}</td>
              <td class="px-3 py-3 font-black text-slate-900">
                {{ number_format((float)$r->total_score_weighted, 2, '.', '') }}
              </td>
              <td class="px-3 py-3">
                {{ $fmtRp($r->os_kol2_turun_total) }}
              </td>
              <td class="px-3 py-3">
                {{ $fmtPct($r->migrasi_npl_pct) }}
              </td>
              <td class="px-3 py-3">
                {{ $fmtRp($r->penalty_paid_total) }}
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="7" class="px-3 py-6 text-center text-slate-500">
                Tidak ada data FE untuk periode ini.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection
