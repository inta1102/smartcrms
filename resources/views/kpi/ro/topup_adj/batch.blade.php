@extends('layouts.app')

@section('content')
@php
  $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtDate = fn($d) => \Carbon\Carbon::parse($d)->format('d M Y');
@endphp

<div class="max-w-7xl mx-auto px-4 py-6 space-y-5">

  <div class="rounded-2xl border border-slate-200 bg-white p-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <div class="text-lg font-extrabold text-slate-900">Batch #{{ $batch->id }}</div>
        <div class="text-xs text-slate-500">
          Period: <span class="font-semibold">{{ $periodMonth }}</span>
          • Status:
          <span class="font-semibold">{{ strtoupper($batch->status) }}</span>
          • Preview as_of: <span class="font-semibold">{{ $asOf }}</span>
          @if($batch->approved_as_of_date)
            • Approved as_of: <span class="font-semibold">{{ $batch->approved_as_of_date->toDateString() }}</span>
          @endif
        </div>
      </div>

      <div class="flex items-center gap-3">
        <a href="{{ route('kpi.ro.topup_adj.index', ['period' => $periodMonth]) }}"
           class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
          Kembali
        </a>

        @if($canApprove && $batch->status==='draft')
          <form method="POST" action="{{ route('kpi.ro.topup_adj.batches.approve', $batch->id) }}">
            @csrf
            <button class="rounded-xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold">
              Approve & Freeze (KBL)
            </button>
          </form>
        @endif
      </div>
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

 {{-- Add line --}}
<div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
    
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <div class="text-lg font-extrabold text-slate-900">
                Tambah Line (per CIF)
            </div>
            <div class="text-sm text-slate-500 mt-1">
                Input hanya CIF + target AO + alasan. 
                Amount dihitung otomatis saat approve (freeze).
            </div>
        </div>

        @if($batch->status !== 'draft')
            <div class="text-sm font-medium text-amber-600">
                Batch sudah approved/cancelled, tidak bisa edit.
            </div>
        @endif
    </div>

    @if($canCreate && $batch->status==='draft')
    <form method="POST"
          action="{{ route('kpi.ro.topup_adj.lines.store', $batch->id) }}"
          class="mt-6 grid grid-cols-1 lg:grid-cols-12 gap-5">
        @csrf

        {{-- CIF --}}
          <div class="lg:col-span-3">
              <label class="block text-sm font-semibold text-slate-700 mb-2">
                  CIF
              </label>
              <div class="relative">
                  <select name="cif" required
                      class="block w-full appearance-none rounded-3xl border border-slate-300 bg-white
                            px-5 py-4 pr-12 text-base
                            focus:ring-2 focus:ring-indigo-500 focus:outline-none
                            transition">
                      <option value="" disabled selected>Pilih CIF...</option>
                      @foreach(($cifOptions ?? []) as $opt)
                          <option value="{{ $opt['cif'] }}">
                              {{ $opt['cif'] }} — {{ $opt['customer_name'] }}
                          </option>
                      @endforeach
                  </select>

                  <svg class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 h-6 w-6 text-slate-400"
                      viewBox="0 0 20 20" fill="currentColor">
                      <path fill-rule="evenodd"
                            d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z"
                            clip-rule="evenodd" />
                  </svg>
              </div>
          </div>

          {{-- Target AO --}}
          <div class="lg:col-span-3">
              <label class="block text-sm font-semibold text-slate-700 mb-2">
                  Target AO (RO)
              </label>
              <select name="target_ao_code" required
                  class="block w-full rounded-3xl border border-slate-300 bg-white
                        px-5 py-4 text-base
                        focus:ring-2 focus:ring-indigo-500 focus:outline-none
                        transition">
                  <option value="">Pilih Target AO...</option>
                  @foreach($ros as $r)
                      <option value="{{ $r->ao_code }}">
                          {{ $r->ao_code }} — {{ $r->name }}
                      </option>
                  @endforeach
              </select>
          </div>

          {{-- Alasan --}}
          <div class="lg:col-span-4">
              <label class="block text-sm font-semibold text-slate-700 mb-2">
                  Alasan
              </label>
              <input name="reason"
                  class="block w-full rounded-3xl border border-slate-300 bg-white
                        px-5 py-4 text-base
                        focus:ring-2 focus:ring-indigo-500 focus:outline-none
                        transition"
                  placeholder="Misal: mutasi AO tengah bulan">
          </div>

          {{-- Button --}}
          <div class="lg:col-span-2 flex items-end">
              <button type="submit"
                  class="w-full flex items-center justify-center gap-2
                        rounded-3xl bg-indigo-600 hover:bg-indigo-700
                        text-white px-8 py-4 text-base font-bold
                        shadow-md hover:shadow-lg active:scale-[0.98]
                        transition-all duration-200">
                  <svg xmlns="http://www.w3.org/2000/svg"
                      class="h-6 w-6"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                      stroke-width="2">
                      <path stroke-linecap="round"
                            stroke-linejoin="round"
                            d="M12 4v16m8-8H4" />
                  </svg>
                  Tambah
              </button>
          </div>

      </form>
      @endif
  </div>

  {{-- Lines --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-5">
    <div class="flex items-center justify-between gap-3">
      <div>
        <div class="text-sm font-bold text-slate-900">Daftar Lines</div>
        <div class="text-xs text-slate-500">
          Total frozen (jika approved): <span class="font-semibold">{{ $fmtRp($sumFrozen) }}</span>
        </div>
      </div>
    </div>

    <div class="mt-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
          <tr class="text-left text-slate-500 border-b">
            <th class="py-2 pr-3">CIF</th>
            <th class="py-2 pr-3">Source AO</th>
            <th class="py-2 pr-3">Target AO</th>
            <th class="py-2 pr-3">Amount Frozen</th>
            <th class="py-2 pr-3">As Of</th>
            <th class="py-2 pr-3">Reason</th>
            <th class="py-2 pr-3">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @forelse($batch->lines as $ln)
            <tr class="border-b">
              <td class="py-2 pr-3 font-semibold text-slate-900">{{ $ln->cif }}</td>
              <td class="py-2 pr-3 text-slate-700">{{ $ln->source_ao_code ?? '-' }}</td>
              <td class="py-2 pr-3 text-slate-700">{{ $ln->target_ao_code }}</td>
              <td class="py-2 pr-3 font-bold">{{ $fmtRp($ln->amount_frozen) }}</td>
              <td class="py-2 pr-3 text-slate-700">
                {{ $ln->calc_as_of_date ? $fmtDate($ln->calc_as_of_date) : '-' }}
              </td>
              <td class="py-2 pr-3 text-slate-700">{{ $ln->reason ?? '-' }}</td>
              <td class="py-2 pr-3">
                @if($canCreate && $batch->status==='draft')
                  <form method="POST" action="{{ route('kpi.ro.topup_adj.lines.delete', [$batch->id, $ln->id]) }}"
                        onsubmit="return confirm('Hapus line ini?');">
                    @csrf
                    @method('DELETE')
                    <button class="text-rose-700 font-semibold hover:underline">Hapus</button>
                  </form>
                @else
                  <span class="text-slate-400">Locked</span>
                @endif
              </td>
            </tr>

            @if(is_array($ln->calc_meta) && !empty($ln->calc_meta))
              <tr class="border-b bg-slate-50">
                <td class="py-2 pr-3 text-xs text-slate-600" colspan="7">
                  <span class="font-semibold">Meta:</span>
                  os_awal={{ $fmtRp($ln->calc_meta['os_awal'] ?? 0) }},
                  sum_disb={{ $fmtRp($ln->calc_meta['sum_disb'] ?? 0) }},
                  net_tu={{ $fmtRp($ln->calc_meta['net_tu'] ?? 0) }},
                  snap_prev={{ $ln->calc_meta['snap_prev'] ?? '-' }},
                  formula={{ $ln->calc_meta['formula_ver'] ?? '-' }}
                </td>
              </tr>
            @endif

          @empty
            <tr><td colspan="7" class="py-3 text-slate-500">Belum ada line.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

</div>
@endsection