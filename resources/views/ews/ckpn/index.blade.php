@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-7xl p-4 space-y-4">

  {{-- Header --}}
  <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
    <div>
      <h1 class="text-xl font-bold text-slate-900">📌 EWS CKPN</h1>
      <p class="text-sm text-slate-500">
        Eligibility per CIF: (RS = YES) atau (DPD > {{ $dpdThreshold }}) dan OS CIF ≥ {{ number_format($minOs,0,',','.') }}.
        Output per rekening (account_no).
      </p>
    </div>
  </div>

  {{-- Filters --}}
  <div class="rounded-2xl border bg-white p-4 shadow-sm">
    <form class="grid grid-cols-1 gap-3 md:grid-cols-6" method="GET" action="{{ route('ews.ckpn.index') }}">
      <div>
        <label class="text-xs text-slate-500">Position Date</label>
        <input type="date"
                name="position_date"
                value="{{ $posDate }}"
                class="mt-1 w-full rounded-xl border px-3 py-2 text-sm" />
      </div>

      <div>
        <label class="text-xs text-slate-500">Min OS CIF</label>
        <input type="number" name="min_os" value="{{ $minOs }}"
               class="mt-1 w-full rounded-xl border px-3 py-2 text-sm" />
      </div>

      <div>
        <label class="text-xs text-slate-500">DPD &gt;</label>
        <input type="number" name="dpd_gt" value="{{ $dpdThreshold }}"
               class="mt-1 w-full rounded-xl border px-3 py-2 text-sm" />
      </div>

      <div>
        <label class="text-xs text-slate-500">Reason</label>
        <select name="reason" class="mt-1 w-full rounded-xl border px-3 py-2 text-sm">
          <option value="all" @selected($reason==='all')>All</option>
          <option value="rs" @selected($reason==='rs')>RS only</option>
          <option value="dpd" @selected($reason==='dpd')>DPD only</option>
          <option value="rs_dpd" @selected($reason==='rs_dpd')>RS + DPD</option>
        </select>
      </div>

      <div class="md:col-span-2">
        <label class="text-xs text-slate-500">Keyword (nama / cif / account_no)</label>
        <input name="q" value="{{ $q }}" placeholder="Contoh: RESPATI / 000123 / 2060..."
               class="mt-1 w-full rounded-xl border px-3 py-2 text-sm" />
      </div>

    </form>

    @php
        $canExport = ($noa ?? 0) > 0;
    @endphp

    <div class="flex flex-wrap items-center gap-2 mt-3">
        {{-- FILTER (primary) --}}
        <button
            type="submit"
            class="h-9 inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 text-sm text-white hover:bg-slate-800">
            {{-- icon funnel --}}
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M2 4a1 1 0 0 1 1-1h14a1 1 0 0 1 .8 1.6l-5.6 7.467V16a1 1 0 0 1-1.447.894l-2-1A1 1 0 0 1 8 15V12.067L2.2 4.6A1 1 0 0 1 2 4z"/>
            </svg>
            <span>Filter</span>
        </button>

        {{-- RESET (secondary) --}}
        <a href="{{ route('ews.ckpn.index') }}"
        class="h-9 inline-flex items-center gap-2 rounded-lg border px-4 text-sm hover:bg-slate-50">
            {{-- icon arrow-path --}}
            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 0 1-9.21 2.475.75.75 0 0 1 1.06-1.06 4 4 0 1 0-.28-5.656H8a.75.75 0 0 1 0 1.5H5.5A.75.75 0 0 1 4.75 8V5.5a.75.75 0 0 1 1.5 0v1.102a5.5 5.5 0 0 1 9.062 4.822Z" clip-rule="evenodd"/>
            </svg>
            <span>Reset</span>
        </a>

        {{-- spacer khusus biar ada jarak antara Reset dan Export --}}
        <span class="w-2"></span>

        {{-- EXPORT (disabled jika NOA=0) --}}
        @if($canExport)
            <a href="{{ route('ews.ckpn.export', request()->query()) }}"
            class="h-9 inline-flex items-center gap-2 rounded-lg border px-4 text-sm hover:bg-emerald-50">
                {{-- icon arrow-down-tray --}}
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v7.19L7.53 8.22a.75.75 0 1 0-1.06 1.06l3 3a.75.75 0 0 0 1.06 0l3-3a.75.75 0 1 0-1.06-1.06l-1.72 1.72V2.75Z"/>
                    <path d="M3.5 12.5A1.5 1.5 0 0 1 5 11h10a1.5 1.5 0 0 1 1.5 1.5v3A1.5 1.5 0 0 1 15 17H5a1.5 1.5 0 0 1-1.5-1.5v-3Z"/>
                </svg>
                <span>Export Excel</span>
            </a>
        @else
            <button type="button"
                class="h-9 inline-flex cursor-not-allowed items-center gap-2 rounded-lg border px-4 text-sm text-slate-400 opacity-60"
                title="Tidak ada data untuk diexport">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10.75 2.75a.75.75 0 0 0-1.5 0v7.19L7.53 8.22a.75.75 0 1 0-1.06 1.06l3 3a.75.75 0 0 0 1.06 0l3-3a.75.75 0 1 0-1.06-1.06l-1.72 1.72V2.75Z"/>
                    <path d="M3.5 12.5A1.5 1.5 0 0 1 5 11h10a1.5 1.5 0 0 1 1.5 1.5v3A1.5 1.5 0 0 1 15 17H5a1.5 1.5 0 0 1-1.5-1.5v-3Z"/>
                </svg>
                <span>Export Excel</span>
            </button>
        @endif
    </div>

  </div>

  {{-- Cards --}}
  <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
    <div class="rounded-2xl border bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">CIF Eligible</div>
      <div class="text-2xl font-bold">{{ number_format($cards->cif_count ?? 0) }}</div>
    </div>

    <div class="rounded-2xl border bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">NOA (rekening)</div>
      <div class="text-2xl font-bold">{{ number_format($noa ?? 0) }}</div>
    </div>

    <div class="rounded-2xl border bg-white p-4 shadow-sm col-span-2 md:col-span-1">
      <div class="text-xs text-slate-500">Total OS CIF</div>
      <div class="text-lg font-semibold tabular-nums">
        {{ number_format((float)($cards->os_total ?? 0), 0, ',', '.') }}
      </div>
    </div>

    <div class="rounded-2xl border bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">CIF RS</div>
      <div class="text-2xl font-bold">{{ number_format($cards->cif_rs ?? 0) }}</div>
    </div>

    <div class="rounded-2xl border bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">CIF DPD &gt; {{ $dpdThreshold }}</div>
      <div class="text-2xl font-bold">{{ number_format($cards->cif_dpd ?? 0) }}</div>
      <div class="text-xs text-slate-500 mt-1">RS+DPD: {{ number_format($cards->cif_rs_dpd ?? 0) }}</div>
    </div>
  </div>

  {{-- List (Mobile) --}}
  <div class="space-y-3 md:hidden">
    <div class="flex items-center justify-between">
      <div>
        <h2 class="font-semibold text-slate-800">Daftar Rekening</h2>
        <p class="text-xs text-slate-500">Posisi: {{ $posDate }}</p>
      </div>
      <div class="text-xs text-slate-500">show: {{ $rows->count() }}</div>
    </div>

    @forelse($rows as $r)
      @php
        $reason = (string)($r->reason ?? '-');
        $badgeClass = match($reason) {
          'RS+DPD' => 'bg-rose-100 text-rose-700 border-rose-200',
          'DPD'    => 'bg-orange-100 text-orange-700 border-orange-200',
          'RS'     => 'bg-amber-100 text-amber-700 border-amber-200',
          default  => 'bg-slate-100 text-slate-700 border-slate-200',
        };
      @endphp

      <div class="rounded-2xl border bg-white p-4 shadow-sm">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <div class="font-semibold text-slate-900 truncate">{{ $r->customer_name }}</div>
            <div class="mt-1 text-xs text-slate-500">
              CIF: <span class="font-mono text-slate-700">{{ $r->cif }}</span>
            </div>
            <div class="mt-1 text-xs text-slate-500">
              Acc: <span class="font-mono text-slate-700">{{ $r->account_no }}</span>
            </div>
            <div class="mt-1 text-xs text-slate-500">
              Produk: <span class="text-slate-700">{{ $r->product_type ?? '-' }}</span>
            </div>
          </div>

          <div class="flex flex-col items-end gap-2 shrink-0">
            <span class="inline-flex rounded-full border px-2 py-1 text-xs font-semibold {{ $badgeClass }}">
              {{ $reason }}
            </span>

            @if((int)$r->is_restructured === 1)
              <span class="inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-700">
                RS
              </span>
            @endif
          </div>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-3">
          <div class="rounded-xl bg-slate-50 p-3">
            <div class="text-[11px] text-slate-500">DPD</div>
            <div class="text-lg font-bold tabular-nums">{{ number_format((int)$r->dpd) }}</div>
          </div>
          <div class="rounded-xl bg-slate-50 p-3">
            <div class="text-[11px] text-slate-500">OS Rek</div>
            <div class="text-sm font-semibold tabular-nums">
              {{ number_format((float)$r->outstanding, 0, ',', '.') }}
            </div>
          </div>
          <div class="rounded-xl bg-slate-50 p-3 col-span-2">
            <div class="text-[11px] text-slate-500">OS CIF</div>
            <div class="text-sm font-semibold tabular-nums">
              {{ number_format((float)$r->os_cif, 0, ',', '.') }}
            </div>
          </div>
        </div>
      </div>
    @empty
      <div class="rounded-2xl border bg-white p-6 text-center text-slate-500">
        Tidak ada data sesuai filter.
      </div>
    @endforelse

    <div class="pt-2">
      {{ $rows->links() }}
    </div>
  </div>

  {{-- Table (Desktop) --}}
  <div class="hidden md:block rounded-2xl border bg-white shadow-sm overflow-hidden">
    <div class="p-4 border-b flex items-center justify-between">
      <div>
        <h2 class="font-semibold text-slate-800">Daftar Rekening (per account_no)</h2>
        <p class="text-xs text-slate-500">Sorted by OS CIF desc, OS rekening desc.</p>
      </div>
      <div class="text-xs text-slate-500">
        Posisi: <span class="font-medium">{{ $posDate }}</span>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-600">
          <tr>
            <th class="text-left px-4 py-3">CIF</th>
            <th class="text-left px-4 py-3">Nama</th>
            <th class="text-left px-4 py-3">Account No</th>
            <th class="text-left px-4 py-3">Product</th>
            <th class="text-center px-4 py-3">RS</th>
            <th class="text-right px-4 py-3">DPD</th>
            <th class="text-right px-4 py-3">OS Rek</th>
            <th class="text-right px-4 py-3">OS CIF</th>
            <th class="text-left px-4 py-3">Reason</th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $r)
            @php
              $reason = (string)($r->reason ?? '-');
              $badgeClass = match($reason) {
                'RS+DPD' => 'bg-rose-100 text-rose-700 border-rose-200',
                'DPD'    => 'bg-orange-100 text-orange-700 border-orange-200',
                'RS'     => 'bg-amber-100 text-amber-700 border-amber-200',
                default  => 'bg-slate-100 text-slate-700 border-slate-200',
              };
            @endphp

            <tr class="border-t">
              <td class="px-4 py-3 font-mono">{{ $r->cif }}</td>
              <td class="px-4 py-3">
                <div class="font-medium text-slate-900">{{ $r->customer_name }}</div>
              </td>
              <td class="px-4 py-3 font-mono">{{ $r->account_no }}</td>
              <td class="px-4 py-3">{{ $r->product_type ?? '-' }}</td>

              <td class="px-4 py-3 text-center">
                @if((int)$r->is_restructured === 1)
                  <span class="inline-flex rounded-full bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-700">YES</span>
                @else
                  <span class="text-slate-400">-</span>
                @endif
              </td>

              <td class="px-4 py-3 text-right tabular-nums">{{ number_format((int)$r->dpd) }}</td>
              <td class="px-4 py-3 text-right tabular-nums">{{ number_format((float)$r->outstanding,0,',','.') }}</td>
              <td class="px-4 py-3 text-right tabular-nums">{{ number_format((float)$r->os_cif,0,',','.') }}</td>

              <td class="px-4 py-3">
                <span class="inline-flex rounded-full border px-2 py-1 text-xs font-semibold {{ $badgeClass }}">
                  {{ $reason }}
                </span>
              </td>
            </tr>
          @empty
            <tr>
              <td colspan="9" class="px-4 py-8 text-center text-slate-500">
                Tidak ada data sesuai filter.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <div class="p-4">
      {{ $rows->links() }}
    </div>
  </div>

</div>
@endsection
