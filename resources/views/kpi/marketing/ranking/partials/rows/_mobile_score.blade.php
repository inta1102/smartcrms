@php
  $rows = $rows ?? collect();
  $role = strtoupper(trim((string)$role));

  $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $dec=2) => number_format((float)($n ?? 0), $dec) . '%';
@endphp

@forelse($rows as $r)
  @php [$uid,$name,$aoCode] = $resolveRow($r); @endphp

  <div class="p-4 border border-slate-200 bg-white rounded-2xl mb-3">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="font-bold text-slate-900 truncate">
          #{{ $r->rank ?? '-' }} - {{ $name }}
        </div>
        <div class="text-xs text-slate-500">
          {{ $role }} Code: <b>{{ $aoCode }}</b>
        </div>
      </div>

      <div class="shrink-0 rounded-xl bg-slate-50 px-3 py-2 text-right">
        <div class="text-[11px] text-slate-500">Total</div>
        <div class="font-bold text-slate-900">{{ number_format((float)($r->score_total ?? 0),2) }}</div>
      </div>
    </div>

    {{-- Role-aware quick metrics --}}
    @if($role === 'RO')
      <div class="mt-3 grid grid-cols-2 gap-2">
        <div class="rounded-xl bg-slate-50 p-2">
          <div class="text-[11px] text-slate-500">TopUp</div>
          <div class="font-bold text-slate-900">{{ $fmtRp($r->topup_realisasi ?? 0) }}</div>
        </div>
        <div class="rounded-xl bg-slate-50 p-2">
          <div class="text-[11px] text-slate-500">Repay</div>
          <div class="font-bold text-slate-900">{{ $fmtPct($r->repayment_pct ?? 0, 2) }}</div>
        </div>
        <div class="rounded-xl bg-slate-50 p-2">
          <div class="text-[11px] text-slate-500">NOA</div>
          <div class="font-bold text-slate-900">{{ number_format((int)($r->noa_realisasi ?? 0)) }}</div>
        </div>
        <div class="rounded-xl bg-slate-50 p-2">
          <div class="text-[11px] text-slate-500">DPK</div>
          <div class="font-bold text-slate-900">{{ $fmtPct($r->dpk_pct ?? 0, 4) }}</div>
        </div>
      </div>

    @elseif($role === 'AO')
      <div class="mt-3 grid grid-cols-2 gap-2">
        <div class="rounded-xl bg-slate-50 p-2">
          <div class="text-[11px] text-slate-500">OS Disb</div>
          <div class="font-bold text-slate-900">{{ $fmtRp($r->os_disb ?? 0) }}</div>
        </div>
        <div class="rounded-xl bg-slate-50 p-2">
          <div class="text-[11px] text-slate-500">NOA Disb</div>
          <div class="font-bold text-slate-900">{{ number_format((int)($r->noa_disb ?? 0)) }}</div>
        </div>
        <div class="rounded-xl bg-slate-50 p-2 col-span-2">
          <div class="text-[11px] text-slate-500">RR</div>
          <div class="font-bold text-slate-900">{{ $fmtPct($r->rr_pct ?? 0, 2) }}</div>
        </div>
      </div>

    @elseif($role === 'SO')
      <div class="mt-3 grid grid-cols-2 gap-2">
        <div class="rounded-xl bg-slate-50 p-2">
          <div class="text-[11px] text-slate-500">OS Disb</div>
          <div class="font-bold text-slate-900">{{ $fmtRp($r->os_disb ?? 0) }}</div>
        </div>
        <div class="rounded-xl bg-slate-50 p-2">
          <div class="text-[11px] text-slate-500">NOA Disb</div>
          <div class="font-bold text-slate-900">{{ number_format((int)($r->noa_disb ?? 0)) }}</div>
        </div>
        <div class="rounded-xl bg-slate-50 p-2 col-span-2">
          <div class="text-[11px] text-slate-500">RR</div>
          <div class="font-bold text-slate-900">{{ $fmtPct($r->rr_pct ?? 0, 2) }}</div>
        </div>
      </div>

    @elseif(in_array($role, ['FE','BE'], true))
      <div class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">
        Skor sudah terisi. Detail metrik {{ $role }} akan kita tampilkan setelah field detail {{ $role }} di-include di query ranking.
      </div>

    @endif

    @if(!$uid)
      <div class="mt-2 text-xs text-red-500">
        Catatan: akun user belum terdaftar / ao_code belum match.
      </div>
    @endif
  </div>

@empty
  <div class="p-4 text-center text-slate-500">
    Belum ada data ranking untuk periode ini.
  </div>
@endforelse
