@extends('layouts.app')

@section('content')
@php
  $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtDate = fn($d) => \Carbon\Carbon::parse($d)->format('d M Y');
@endphp

<div class="max-w-7xl mx-auto px-4 py-6 space-y-5">

  <div class="rounded-2xl border border-slate-200 bg-white p-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      
        <div class="text-lg font-extrabold text-slate-900">TopUp Adjustment (Hybrid)</div>
          <form method="GET" class="flex flex-col sm:flex-row gap-2 sm:items-center">
            {{-- Period (Dropdown) --}}
            <div class="relative w-full sm:w-44">
              <select name="period"
                class="w-full appearance-none rounded-xl border border-slate-200 bg-white text-sm px-3 py-2 pr-9
                      focus:outline-none focus:ring-2 focus:ring-slate-300 focus:border-slate-300">
                @foreach(($periodOptions ?? []) as $opt)
                  <option value="{{ $opt }}" @selected(request('period', $periodMonth) === $opt)>
                    {{ $opt }}
                  </option>
                @endforeach
              </select>

              {{-- chevron --}}
              <svg class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400"
                  viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd"
                      d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z"
                      clip-rule="evenodd" />
              </svg>
            </div>

            {{-- Cari CIF --}}
            <input type="text" name="cif" value="{{ $searchCif }}"
                  class="rounded-xl border border-slate-200 text-sm px-3 py-2 w-full sm:w-56
                          focus:outline-none focus:ring-2 focus:ring-slate-300 focus:border-slate-300"
                  placeholder="Cari CIF..." />

            <button class="rounded-xl bg-slate-900 text-white px-4 py-2 text-sm font-semibold w-full sm:w-auto">
              Filter
            </button>
          </form>
      
    </div>

    @if(session('ok'))
      <div class="mt-3 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 px-3 py-2 text-sm">
        {{ session('ok') }}
      </div>
    @endif
    @if(session('err'))
      <div class="mt-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 px-3 py-2 text-sm">
        {{ session('err') }}
      </div>
    @endif
  </div>

  {{-- Create Batch --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-5">
    <div class="flex items-center justify-between gap-3">
      <div>
        <div class="text-sm font-bold text-slate-900">Batch Draft</div>
        <div class="text-xs text-slate-500">TLRO/KSLR membuat draft, KBL yang approve (freeze).</div>
      </div>

      @if($canCreate)
      <form method="POST" action="{{ route('kpi.ro.topup_adj.batches.store') }}" class="flex gap-2 items-center">
        @csrf
        <input type="hidden" name="period_month" value="{{ $periodMonth }}">
        <input type="text" name="notes" class="rounded-xl border-slate-200 text-sm px-3 py-2 w-72" placeholder="Catatan batch (optional)">
        <button class="rounded-xl bg-indigo-600 text-white px-4 py-2 text-sm font-semibold">Buat Batch</button>
      </form>
      @else
        <div class="text-xs text-slate-500">Kamu tidak punya akses membuat draft.</div>
      @endif
    </div>

    <div class="mt-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-slate-500 border-b">
            <th class="py-2 pr-3">Batch</th>
            <th class="py-2 pr-3">Status</th>
            <th class="py-2 pr-3">Lines</th>
            <th class="py-2 pr-3">Approved As Of</th>
            <th class="py-2 pr-3">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($batches as $b)
            <tr class="border-b">
              <td class="py-2 pr-3 font-semibold text-slate-900">#{{ $b->id }}</td>
              <td class="py-2 pr-3">
                @php
                  $cls = $b->status==='approved' ? 'bg-emerald-100 text-emerald-800' : ($b->status==='draft' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700');
                @endphp
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-bold {{ $cls }}">
                  {{ strtoupper($b->status) }}
                </span>
              </td>
              <td class="py-2 pr-3">{{ $b->lines_count }}</td>
              <td class="py-2 pr-3 text-slate-700">
                {{ $b->approved_as_of_date ? $fmtDate($b->approved_as_of_date) : '-' }}
              </td>
              <td class="py-2 pr-3">
                <a href="{{ route('kpi.ro.topup_adj.batches.show', $b->id) }}"
                   class="text-indigo-700 font-semibold hover:underline">
                  Buka
                </a>
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="py-3 text-slate-500">Belum ada batch untuk periode ini.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- Candidates --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-5">
    <div class="flex items-center justify-between gap-3">
      <div>
        <div class="text-sm font-bold text-slate-900">Kandidat CIF (Preview Realtime)</div>
        <div class="text-xs text-slate-500">
          Net TU = IF(os_awal>0, max(sum_disb - os_awal,0), 0). Ini preview, amount freeze saat approve.
        </div>
      </div>
      <div class="text-xs text-slate-500">
        Cutoff (as_of): <span class="font-semibold">{{ $candidates['as_of_date'] ?? '-' }}</span>
      </div>
    </div>

    <div class="mt-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-slate-500 border-b">
            <th class="py-2 pr-3">CIF</th>
            <th class="py-2 pr-3">Source AO (suggest)</th>
            <th class="py-2 pr-3">OS Awal</th>
            <th class="py-2 pr-3">Sum Disb</th>
            <th class="py-2 pr-3">Net TU</th>
          </tr>
        </thead>
        <tbody>
          @forelse(($candidates['items'] ?? []) as $it)
            <tr class="border-b">
              <td class="py-2 pr-3 font-semibold text-slate-900">{{ $it['cif'] }}</td>
              <td class="py-2 pr-3 text-slate-700">{{ $it['source_ao_code'] ?? '-' }}</td>
              <td class="py-2 pr-3">{{ $fmtRp($it['os_awal'] ?? 0) }}</td>
              <td class="py-2 pr-3">{{ $fmtRp($it['sum_disb'] ?? 0) }}</td>
              <td class="py-2 pr-3 font-bold">{{ $fmtRp($it['net_tu'] ?? 0) }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="py-3 text-slate-500">Tidak ada kandidat (coba ganti period atau kata kunci CIF).</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection