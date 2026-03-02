@extends('layouts.app')

@section('content')
@php
  $fmt = fn($n, $d=2) => number_format((float)($n ?? 0), $d, ',', '.');
  $fmtPct = fn($n,$d=2) => $fmt($n,$d).'%';
@endphp

<div class="max-w-7xl mx-auto p-4 sm:p-6 space-y-4">

  {{-- FILTER BAR --}}
  <form method="GET" class="rounded-2xl border border-slate-200 bg-white p-4">
    <div class="grid grid-cols-1 sm:grid-cols-12 gap-2">
      <div class="sm:col-span-2">
        <label class="text-xs text-slate-500 font-bold">Period</label>
        <input name="period" value="{{ $filters['period'] ?? '' }}"
          class="w-full rounded-xl border-slate-200 text-sm"
          placeholder="2026-02" />
      </div>

      <div class="sm:col-span-2">
        <label class="text-xs text-slate-500 font-bold">Level</label>
        <select name="level" class="w-full rounded-xl border-slate-200 text-sm">
          @foreach(['ALL','RO','TLRO','KBL'] as $opt)
            <option value="{{ $opt }}" @selected(($filters['level'] ?? 'ALL')===$opt)>{{ $opt }}</option>
          @endforeach
        </select>
      </div>

      <div class="sm:col-span-2">
        <label class="text-xs text-slate-500 font-bold">Role</label>
        <select name="role" class="w-full rounded-xl border-slate-200 text-sm">
          @foreach(['ALL','RO','TLRO','KSLR'] as $opt)
            <option value="{{ $opt }}" @selected(($filters['role'] ?? 'ALL')===$opt)>{{ $opt }}</option>
          @endforeach
        </select>
      </div>

      <div class="sm:col-span-4">
        <label class="text-xs text-slate-500 font-bold">Search</label>
        <input name="q" value="{{ $filters['q'] ?? '' }}"
          class="w-full rounded-xl border-slate-200 text-sm"
          placeholder="nama / ao_code" />
      </div>

      <div class="sm:col-span-2 flex items-end gap-2">
        <button class="w-full rounded-xl bg-slate-900 text-white text-sm font-bold px-4 py-2">
          Terapkan
        </button>
      </div>
    </div>
  </form>

  {{-- TABLE --}}
  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100">
      <div class="text-sm font-extrabold text-slate-900">Ringkasan KPI (Staff → TL → Kasi)</div>
      <div class="text-xs text-slate-500">Klik “Detail” untuk breakdown komponen & audit source.</div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr class="text-left">
            <th class="px-4 py-3 font-bold">Level</th>
            <th class="px-4 py-3 font-bold">Role</th>
            <th class="px-4 py-3 font-bold">Nama</th>
            <!-- <th class="px-4 py-3 font-bold">Scope</th> -->
            <th class="px-4 py-3 font-bold">Period</th>
            <th class="px-4 py-3 font-bold">Mode</th>
            <th class="px-4 py-3 font-bold text-right">Score</th>
            <!-- <th class="px-4 py-3 font-bold text-right">Ach%</th> -->
            <th class="px-4 py-3 font-bold text-right">Rank</th>
            <!-- <th class="px-4 py-3 font-bold">Risk</th>
            <th class="px-4 py-3 font-bold text-right">Δ</th> -->
            <th class="px-4 py-3 font-bold">Aksi</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-100">
          @forelse(($rows ?? []) as $i => $r)
            @php
              $rowId = 'kpi_row_'.$i;
              $lvl = strtoupper((string)($r['level'] ?? '-'));
              $role = strtoupper((string)($r['role'] ?? '-'));
              $risk = strtoupper((string)($r['risk_badge'] ?? ''));
              $delta = $r['delta_pp'] ?? null;

              $riskCls = match($risk){
                'LOW' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
                'MED' => 'bg-amber-100 text-amber-800 border-amber-200',
                'HIGH'=> 'bg-rose-100 text-rose-800 border-rose-200',
                default => 'bg-slate-100 text-slate-700 border-slate-200'
              };

              $lvlCls = match($lvl){
                'STAFF' => 'bg-slate-100 text-slate-700 border-slate-200',
                'TL'    => 'bg-indigo-100 text-indigo-800 border-indigo-200',
                'KASI'  => 'bg-fuchsia-100 text-fuchsia-800 border-fuchsia-200',
                default => 'bg-slate-100 text-slate-700 border-slate-200'
              };

              $dCls = $delta === null ? 'text-slate-400' : ($delta >= 0 ? 'text-emerald-700' : 'text-rose-700');
              $dText = $delta === null ? '-' : ($delta >= 0 ? '+'.$fmt($delta,2) : $fmt($delta,2));
            @endphp

            <tr class="hover:bg-slate-50">
              <td class="px-4 py-3">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $lvlCls }}">
                  {{ $lvl }}
                </span>
              </td>
              <td class="px-4 py-3 font-semibold text-slate-900">{{ $role }}</td>
              <td class="px-4 py-3 text-slate-900">
                {{ $r['name'] ?? '-' }}
                @if(!empty($r['unit']))
                  <div class="text-[11px] text-slate-500">{{ $r['unit'] }}</div>
                @endif
              </td>
              <!-- <td class="px-4 py-3 text-slate-600">
                {{ isset($r['scope_count']) ? (int)$r['scope_count'].' org' : '-' }}
              </td> -->
              <td class="px-4 py-3 text-slate-600">{{ $r['period'] ?? '-' }}</td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border bg-slate-50 text-slate-700 border-slate-200">
                  {{ strtoupper($r['mode'] ?? '-') }}
                </span>
              </td>
              <td class="px-4 py-3 text-right font-extrabold text-slate-900">{{ $fmt($r['score'] ?? 0, 2) }}</td>
              <!-- <td class="px-4 py-3 text-right text-slate-700">{{ $fmtPct($r['ach'] ?? 0, 2) }}</td> -->
              <td class="px-4 py-3 text-right text-slate-600">{{ $r['rank'] ?? '-' }}</td>
              <!-- <td class="px-4 py-3">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold border {{ $riskCls }}">
                  {{ $risk ?: '-' }}
                </span>
              </td>
              <td class="px-4 py-3 text-right font-bold {{ $dCls }}">{{ $dText }}</td> -->
              <td class="px-4 py-3">
                <button type="button" class="text-xs font-bold text-indigo-700 hover:underline"
                  onclick="document.getElementById('{{ $rowId }}').classList.toggle('hidden')">
                  Detail
                </button>
              </td>
            </tr>

            <tr id="{{ $rowId }}" class="hidden bg-slate-50">
              <td colspan="12" class="px-4 py-4">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                  <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-extrabold text-slate-900 mb-2">Breakdown Komponen</div>
                    <div class="space-y-2">
                      @forelse(($r['detail']['components'] ?? []) as $c)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs">
                          <div class="font-bold text-slate-900">{{ $c['label'] ?? '-' }}</div>
                          <div class="text-slate-600">Value: {{ $fmt($c['val'] ?? 0, 2) }} | Target: {{ $fmt($c['target'] ?? 0, 2) }}</div>
                          <div class="text-slate-600">W: {{ $fmt($c['w'] ?? 0, 2) }} | Score: <span class="font-bold text-slate-900">{{ $fmt($c['score'] ?? 0, 2) }}</span></div>
                        </div>
                      @empty
                        <div class="text-xs text-slate-500">Belum ada komponen detail.</div>
                      @endforelse
                    </div>
                  </div>

                  <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-extrabold text-slate-900 mb-2">Audit & Source</div>
                    <div class="space-y-2">
                      @forelse(($r['detail']['audit'] ?? []) as $a)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs">
                          <div class="font-bold text-slate-900">{{ $a['k'] ?? '-' }}</div>
                          <div class="text-slate-600">Source: {{ $a['src'] ?? '-' }}</div>
                          <div class="text-slate-600">Rule: {{ $a['rule'] ?? '-' }}</div>
                        </div>
                      @empty
                        <div class="text-xs text-slate-500">Audit belum tersedia.</div>
                      @endforelse
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="12" class="px-4 py-6 text-center text-slate-500">Data belum tersedia.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection