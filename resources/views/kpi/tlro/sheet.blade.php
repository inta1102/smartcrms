@extends('layouts.app')

@section('title', 'KPI TLRO · Leadership Index')

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-5">

  {{-- =========================
      Header
     ========================= --}}
  <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
    <div>
      <div class="text-sm text-slate-500">Periode</div>
      <div class="text-3xl sm:text-4xl font-black text-slate-900">{{ $periodLabel }}</div>

      <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-1 text-slate-700">
          Role: <b class="ml-1">TLRO</b>
        </span>

        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-1 text-slate-700">
          Mode: <b class="ml-1">{{ $modeLabel }}</b>
        </span>

        <span class="inline-flex items-center rounded-full border border-slate-200 bg-white px-2 py-1 text-slate-700">
          RO Scope: <b class="ml-1">{{ (int)($row->ro_count ?? $roRows->count() ?? 0) }}</b>
        </span>

        @if(!empty($subjectUser) && !empty($viewerUser) && ((int)($subjectUser->id ?? 0) !== (int)($viewerUser->id ?? 0)))
          <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 px-2 py-1 text-amber-900">
            View as: <b class="ml-1">{{ $subjectUser->name ?? 'TLRO' }}</b>
          </span>
        @endif
      </div>
    </div>

    {{-- Actions --}}
    <div class="flex flex-col sm:flex-row gap-2 sm:items-center">

      <form method="GET" action="{{ route('kpi.tlro.sheet') }}" class="flex items-center gap-2">
        <input type="month"
               name="period"
               value="{{ \Carbon\Carbon::parse($period)->format('Y-m') }}"
               class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300">

        {{-- kalau sedang view as, pertahankan tlro_id --}}
        @if(request()->query('tlro_id'))
          <input type="hidden" name="tlro_id" value="{{ (int)request()->query('tlro_id') }}">
        @endif

        <button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
          Lihat
        </button>
      </form>

      <form method="POST" action="{{ route('kpi.tlro.sheet.recalc') }}">
        @csrf
        <input type="hidden" name="period" value="{{ $period }}">
        <button class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">
          Recalc
        </button>
      </form>

    </div>
  </div>

  {{-- Flash --}}
  @if(session('success'))
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-900">
      {{ session('success') }}
    </div>
  @endif

  {{-- =========================
      KPI Cards
     ========================= --}}
  @php
    $li = (float)($row->leadership_index ?? 0);
    $status = strtoupper((string)($row->status_label ?? ''));

    $badge = $li >= 4.5 ? ['AMAN','bg-emerald-100 text-emerald-800 border-emerald-200']
            : ($li >= 3.5 ? ['CUKUP','bg-sky-100 text-sky-800 border-sky-200']
            : ($li >= 2.5 ? ['WASPADA','bg-amber-100 text-amber-800 border-amber-200']
            : ['KRITIS','bg-rose-100 text-rose-800 border-rose-200']));
  @endphp

  <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 sm:gap-3">

    {{-- Leadership Index --}}
    <div class="col-span-2 sm:col-span-1 rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-xs text-slate-500">Leadership Index</div>
      <div class="mt-1 text-3xl font-black text-slate-900">{{ $fmt2($row->leadership_index ?? 0) }}</div>
      <div class="mt-2">
        <span class="inline-flex items-center rounded-full border px-2 py-1 text-xs font-semibold {{ $badge[1] }}">
          {{ $badge[0] }}
        </span>
      </div>
      <div class="mt-2 text-[11px] text-slate-500">Agregat leadership RO (scope TLRO)</div>
    </div>

    {{-- PI Scope --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-xs text-slate-500">PI_scope</div>
      <div class="mt-1 text-2xl font-extrabold text-slate-900">{{ $fmt2($row->pi_scope ?? 0) }}</div>
      <div class="mt-2 text-[11px] text-slate-500">Avg score RO (scope)</div>
    </div>

    {{-- Stability --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-xs text-slate-500">Stability</div>
      <div class="mt-1 text-2xl font-extrabold text-slate-900">
        {{ is_null($row->stability_index) ? '-' : $fmt2($row->stability_index) }}
      </div>
      <div class="mt-2 text-[11px] text-slate-500">Sebaran antar RO</div>
    </div>

    {{-- Risk --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-xs text-slate-500">Risk</div>
      <div class="mt-1 text-2xl font-extrabold text-slate-900">
        {{ is_null($row->risk_index) ? '-' : $fmt2($row->risk_index) }}
      </div>
      <div class="mt-2 text-[11px] text-slate-500">Governance risk (LT/DPK)</div>
    </div>

    {{-- Improvement --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3 sm:p-4">
      <div class="text-xs text-slate-500">Improvement</div>
      <div class="mt-1 text-2xl font-extrabold text-slate-900">
        {{ is_null($row->improvement_index) ? '-' : $fmt2($row->improvement_index) }}
      </div>
      <div class="mt-2 text-[11px] text-slate-500">MoM delta PI_scope</div>
    </div>

  </div>

  {{-- =========================
      AI Leadership Engine
     ========================= --}}
  <div class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-6">
    <div class="flex items-start justify-between gap-3">
      <div>
        <div class="text-sm text-slate-500">AI Leadership Engine</div>
        <div class="mt-1 text-xl sm:text-2xl font-black text-slate-900">{{ $aiTitle ?? 'Ringkasan TLRO' }}</div>
      </div>
      <div class="shrink-0">
        <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700">
          {{ $status ?: 'N/A' }}
        </span>
      </div>
    </div>

    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <div class="text-xs font-semibold text-slate-700">Insight</div>
        <ul class="mt-2 space-y-1 text-sm text-slate-700 list-disc pl-5">
          @foreach(($aiBullets ?? []) as $b)
            <li>{{ $b }}</li>
          @endforeach
        </ul>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
        <div class="text-xs font-semibold text-slate-700">Actions Now</div>
        <ol class="mt-2 space-y-1 text-sm text-slate-700 list-decimal pl-5">
          @foreach(($aiActions ?? []) as $a)
            <li>{{ $a }}</li>
          @endforeach
        </ol>
      </div>
    </div>
  </div>

  {{-- =========================
      Breakdown RO
     ========================= --}}
  <div class="rounded-3xl border border-slate-200 bg-white overflow-hidden">
    <div class="px-4 sm:px-6 py-4 border-b border-slate-200">
      <div class="flex items-center justify-between gap-3">
        <div>
          <div class="text-sm text-slate-500">Breakdown</div>
          <div class="text-lg font-extrabold text-slate-900">RO dalam Scope TLRO</div>
        </div>
        <div class="text-xs text-slate-500">
          Total: <b>{{ $roRows->count() }}</b>
        </div>
      </div>
    </div>

    @if($roRows->isEmpty())
      <div class="p-6 text-sm text-slate-600">
        Belum ada data RO untuk periode ini. Pastikan:
        <ul class="list-disc pl-5 mt-2 space-y-1">
          <li>org_assignments TLRO → RO sudah benar</li>
          <li>KPI RO sudah terbentuk untuk period yang sama</li>
        </ul>
      </div>
    @else
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50 text-slate-600">
            <tr class="border-b">
              <th class="text-left px-4 py-3">Rank</th>
              <th class="text-left px-4 py-3">RO</th>
              <th class="text-right px-4 py-3">OS</th>
              <th class="text-right px-4 py-3">RR%</th>
              <th class="text-right px-4 py-3">LT%</th>
              <th class="text-right px-4 py-3">DPK%</th>
              <th class="text-right px-4 py-3">Risk</th>
              <th class="text-right px-4 py-3">Score</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-200">
            @foreach($roRows as $i => $r)
              @php
                $score = (float)($r->total_score_weighted ?? 0);
                $risk2 = (float)($r->risk_index ?? 0);

                // badge berdasarkan score (simple)
                $b2 = $score >= 4.5 ? ['AMAN','bg-emerald-100 text-emerald-800 border-emerald-200']
                    : ($score >= 3.5 ? ['CUKUP','bg-sky-100 text-sky-800 border-sky-200']
                    : ($score >= 2.5 ? ['WASPADA','bg-amber-100 text-amber-800 border-amber-200']
                    : ['KRITIS','bg-rose-100 text-rose-800 border-rose-200']));

                $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
              @endphp

              <tr class="hover:bg-slate-50">
                <!-- <div class="text-[10px] text-slate-400">daily: {{ $r->daily_date ?? '-' }}</div> -->
                <td class="px-4 py-3 font-semibold">{{ $i+1 }}</td>

                <td class="px-4 py-3">
                    @php
                    $roSheetUrl = !empty($r->ro_user_id)
                        ? route('kpi.ro.user.show', [
                            'user'   => (int) $r->ro_user_id,
                            'period' => $period, // period TLRO sekarang (Y-m-d)
                            ])
                        : null;
                    @endphp

                    @if($roSheetUrl)
                        <a href="{{ $roSheetUrl }}" class="hover:underline text-slate-900">
                            {{ $r->ro_name ?: ('RO '.$r->ao_code) }}
                        </a>
                    @else
                        {{ $r->ro_name ?: ('RO '.$r->ao_code) }}
                    @endif

                    <div class="text-xs text-slate-500">
                        @if(!empty($r->ro_user_id))
                            ID: {{ $r->ro_user_id }}
                        @endif
                    <span class="ml-2">AO: {{ $r->ao_code }}</span>
                    </div>
                </td>

                <td class="px-4 py-3 text-right font-semibold text-slate-900">
                  {{ $fmtRp($r->os_total ?? 0) }}
                </td>

                <td class="px-4 py-3 text-right">{{ $fmt2($r->rr_pct ?? 0) }}%</td>
                <td class="px-4 py-3 text-right">
                    {{ is_null($r->lt_pct ?? null) ? '-' : ($fmt2($r->lt_pct) . '%') }}
                </td>
                <td class="px-4 py-3 text-right">{{ $fmt2($r->dpk_pct ?? 0) }}%</td>

                @php
                    $risk = (int)($r->risk_index ?? 0);

                    $riskBadge = $risk >= 5 ? ['KRITIS','bg-rose-100 text-rose-800 border-rose-200']
                                : ($risk >= 3 ? ['WASPADA','bg-amber-100 text-amber-800 border-amber-200']
                                : ['AMAN','bg-emerald-100 text-emerald-800 border-emerald-200']);
                @endphp

                <td class="px-4 py-3 text-right">
                <div class="font-bold text-slate-900">{{ $risk ?: '-' }}</div>
                @if($risk)
                    <div class="mt-1">
                    <span class="inline-flex items-center rounded-full border px-2 py-1 text-[11px] font-semibold {{ $riskBadge[1] }}">
                        {{ $riskBadge[0] }}
                    </span>
                    </div>
                @endif
                </td>

                <td class="px-4 py-3 text-right">
                  <div class="font-extrabold text-slate-900">{{ $fmt2($score) }}</div>
                  <div class="mt-1">
                    <span class="inline-flex items-center rounded-full border px-2 py-1 text-[11px] font-semibold {{ $b2[1] }}">
                      {{ $b2[0] }}
                    </span>
                  </div>
                </td>

              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

</div>
@endsection