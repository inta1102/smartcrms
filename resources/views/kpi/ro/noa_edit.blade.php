@extends('layouts.app')

@section('content')
@php
  // Helpers kecil biar rapi
  $fmtInt = fn($n) => number_format((int)($n ?? 0), 0, ',', '.');

  $periodLabel = isset($period) ? \Carbon\Carbon::parse($period)->format('F Y') : now()->format('F Y');
  $periodYmd   = $periodDate ?? (isset($period) ? \Carbon\Carbon::parse($period)->startOfMonth()->toDateString() : now()->startOfMonth()->toDateString());
  $periodYm    = \Carbon\Carbon::parse($periodYmd)->format('Y-m');

  // style
  $card = 'rounded-2xl border border-slate-200 bg-white shadow-sm';
  $btn  = 'inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold';
  $inp  = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-slate-300';
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

  {{-- Header --}}
  <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-start gap-3">
      <div class="h-10 w-10 rounded-xl bg-slate-900 text-white flex items-center justify-center font-black">
        KPI
      </div>
      <div>
        <div class="text-xl sm:text-2xl font-extrabold text-slate-900 leading-tight">
          Input NOA Pengembangan (Manual)
        </div>
        <div class="text-sm text-slate-500">
          Role: <span class="font-semibold">{{ strtoupper(auth()->user()?->roleValue() ?? '-') }}</span>
          • Period: <span class="font-semibold">{{ $periodLabel }}</span>
        </div>
      </div>
    </div>

    {{-- Period selector --}}
    <form method="GET" action="{{ route('kpi.ro.noa.edit') }}" class="flex items-center gap-2">
      <input
        type="month"
        name="period"
        value="{{ $periodYm }}"
        class="{{ $inp }} w-[160px]"
      />
      <button class="{{ $btn }} bg-slate-900 text-white hover:bg-slate-800">
        Tampilkan
      </button>

      <a href="{{ route('kpi.marketing.sheet', ['role'=>'RO', 'period'=>$period->format('Y-m'), 'mode'=>$mode ?? 'realtime']) }}"
         class="{{ $btn }} bg-emerald-600 text-white hover:bg-emerald-700">
        Kembali ke KPI RO
      </a>
    </form>
  </div>

  {{-- Flash / Errors --}}
  <div class="mt-4">
    @if(session('ok'))
      <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm">
        {{ session('ok') }}
      </div>
    @endif

    @if($errors->any())
      <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 text-sm mt-3">
        <div class="font-bold mb-1">Ada error:</div>
        <ul class="list-disc pl-5 space-y-1">
          @foreach($errors->all() as $e)
            <li>{{ $e }}</li>
          @endforeach
        </ul>
      </div>
    @endif
  </div>

  {{-- Summary card --}}
  <div class="{{ $card }} mt-5 p-4 sm:p-5">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <div class="text-sm font-bold text-slate-900">Catatan penting</div>
        <div class="text-sm text-slate-600 mt-1">
          NOA Pengembangan untuk RO <span class="font-semibold">diinput manual oleh TLRO/KSLR/KBL</span>.
          Angka ini akan dipakai sebagai <span class="font-semibold">Actual NOA</span> pada KPI RO Sheet.
        </div>
      </div>
      <div class="flex items-center gap-2">
        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200">
          Periode: {{ \Carbon\Carbon::parse($periodYmd)->format('d M Y') }}
        </span>
        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-indigo-50 text-indigo-700 border-indigo-200">
          Input bulk
        </span>
      </div>
    </div>
  </div>

  {{-- Form Bulk --}}
  <form method="POST" action="{{ route('kpi.ro.noa.upsert') }}" class="mt-5">
    @csrf
    <input type="hidden" name="period" value="{{ $periodYmd }}"/>

    <div class="{{ $card }} overflow-hidden">
      <div class="px-4 sm:px-5 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
          <div class="text-sm font-bold text-slate-900">Daftar RO</div>
          <div class="text-xs text-slate-500">
            Isi NOA Pengembangan per AO Code lalu klik <span class="font-semibold">Simpan</span>.
          </div>
        </div>

        <div class="flex items-center gap-2">
          <button type="button"
                  onclick="window.KPI_NOA?.fillZeros()"
                  class="{{ $btn }} bg-slate-100 text-slate-800 hover:bg-slate-200">
            Set kosong → 0
          </button>

          <button type="submit"
                  class="{{ $btn }} bg-emerald-600 text-white hover:bg-emerald-700">
            Simpan
          </button>
        </div>
      </div>

      {{-- Table --}}
      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-50">
            <tr class="text-left text-slate-600">
              <th class="px-4 sm:px-5 py-3 font-semibold">RO</th>
              <th class="px-4 sm:px-5 py-3 font-semibold">AO Code</th>
              <th class="px-4 sm:px-5 py-3 font-semibold">Target NOA</th>
              <th class="px-4 sm:px-5 py-3 font-semibold">Actual NOA (Manual)</th>
              <th class="px-4 sm:px-5 py-3 font-semibold">Catatan</th>
              <th class="px-4 sm:px-5 py-3 font-semibold">Last Input</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-100">
            @forelse($ros as $i => $ro)
              @php
                $ao = str_pad(trim((string)$ro->ao_code), 6, '0', STR_PAD_LEFT);
                $tg = $targets[$ao] ?? null;
                $mn = $manuals[$ao] ?? null;

                $targetNoa = (int)($tg->target_noa ?? 0);

                $valNoa = old("rows.$i.noa_pengembangan");
                if ($valNoa === null) $valNoa = (int)($mn->noa_pengembangan ?? 0);

                $valNotes = old("rows.$i.notes");
                if ($valNotes === null) $valNotes = (string)($mn->notes ?? '');

                $last = $mn?->input_at ?? null;
              @endphp

              <tr class="hover:bg-slate-50/40">
                <td class="px-4 sm:px-5 py-3">
                  <div class="font-semibold text-slate-900">{{ $ro->name }}</div>
                  <div class="text-xs text-slate-500">User ID: {{ $ro->id }}</div>
                </td>

                <td class="px-4 sm:px-5 py-3 font-mono text-slate-800">
                  {{ $ao }}
                  <input type="hidden" name="rows[{{ $i }}][ao_code]" value="{{ $ao }}">
                </td>

                <td class="px-4 sm:px-5 py-3">
                  <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200">
                    {{ $fmtInt($targetNoa) }}
                  </span>
                </td>

                <td class="px-4 sm:px-5 py-3">
                  <input
                    type="number"
                    min="0"
                    step="1"
                    inputmode="numeric"
                    class="{{ $inp }} w-[140px] noa-input"
                    name="rows[{{ $i }}][noa_pengembangan]"
                    value="{{ $valNoa }}"
                    data-ao="{{ $ao }}"
                  />
                  <div class="text-[11px] text-slate-500 mt-1">
                    Dipakai sebagai actual NOA di KPI sheet.
                  </div>
                </td>

                <td class="px-4 sm:px-5 py-3">
                  <input
                    type="text"
                    class="{{ $inp }} min-w-[220px]"
                    name="rows[{{ $i }}][notes]"
                    value="{{ $valNotes }}"
                    placeholder="Opsional: alasan/uraian"
                  />
                </td>

                <td class="px-4 sm:px-5 py-3">
                  @if($last)
                    <div class="text-xs text-slate-700 font-semibold">
                      {{ \Carbon\Carbon::parse($last)->format('d M Y H:i') }}
                    </div>
                    <div class="text-[11px] text-slate-500">
                      by ID: {{ $mn->input_by ?? '-' }}
                    </div>
                  @else
                    <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-amber-50 text-amber-700 border-amber-200">
                      Belum diinput
                    </span>
                  @endif
                </td>
              </tr>

            @empty
              <tr>
                <td colspan="6" class="px-4 sm:px-5 py-10 text-center text-slate-500">
                  Tidak ada RO dalam scope kamu untuk periode ini.
                </td>
              </tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <div class="px-4 sm:px-5 py-4 border-t border-slate-100 flex items-center justify-between">
        <div class="text-xs text-slate-500">
          Tip: kamu bisa isi cepat lalu klik <span class="font-semibold">Simpan</span>. Sistem akan upsert per AO Code.
        </div>

        <button type="submit" class="{{ $btn }} bg-emerald-600 text-white hover:bg-emerald-700">
          Simpan
        </button>
      </div>
    </div>
  </form>
</div>

{{-- Small JS helpers --}}
<script>
  window.KPI_NOA = {
    fillZeros() {
      document.querySelectorAll('.noa-input').forEach((el) => {
        if (el.value === '' || el.value === null) el.value = 0;
      });
    }
  };
</script>
@endsection