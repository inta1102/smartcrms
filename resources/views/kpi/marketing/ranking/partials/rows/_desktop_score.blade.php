@php
  $rows = $rows ?? collect();

  $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $dec=2) => number_format((float)($n ?? 0), $dec) . '%';

  $role = strtoupper(trim((string)$role));
@endphp

<div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
  <div class="p-4 border-b border-slate-200">
    <div class="font-bold text-slate-900">Daftar Ranking</div>
    <div class="text-xs text-slate-500">
      Urut: Total Score ({{ $role }}) @if($role==='RO') – Weighted RO @endif
    </div>
  </div>

  <div class="overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr class="text-slate-600">
          <th class="p-3 text-left w-16">Rank</th>
          <th class="p-3 text-left">{{ $role }}</th>

          {{-- kolom umum --}}
          <th class="p-3 text-right">Total</th>

          {{-- kolom per role --}}
          @if($role==='RO')
            <th class="p-3 text-right">Repay %</th>
            <th class="p-3 text-right">TopUp</th>
            <th class="p-3 text-right">NOA</th>
            <th class="p-3 text-right">DPK %</th>
            <th class="p-3 text-right">Migrasi</th>

          @elseif($role==='AO')
            <th class="p-3 text-right">OS Disb</th>
            <th class="p-3 text-right">NOA Disb</th>
            <th class="p-3 text-right">RR</th>
            <th class="p-3 text-right">OS Score</th>
            <th class="p-3 text-right">NOA Score</th>

          @elseif($role==='SO')
            <th class="p-3 text-right">OS Disb</th>
            <th class="p-3 text-right">NOA Disb</th>
            <th class="p-3 text-right">RR</th>
            <th class="p-3 text-right">OS Score</th>
            <th class="p-3 text-right">NOA Score</th>

          @elseif($role==='FE')
            <th class="p-3 text-right">Catatan</th>

          @elseif($role==='BE')
            <th class="p-3 text-right">Catatan</th>

          @else
            <th class="p-3 text-right">OS</th>
            <th class="p-3 text-right">NOA</th>
          @endif
        </tr>
      </thead>

      <tbody class="divide-y">
        @forelse($rows as $r)
          @php
            [$uid,$name,$aoCode] = $resolveRow($r);
          @endphp

          <tr class="hover:bg-slate-50">
            <td class="p-3 font-semibold">{{ $r->rank ?? '-' }}</td>

            <td class="p-3">
              <div class="font-semibold text-slate-900">{{ $name }}</div>
              <div class="text-xs text-slate-500">{{ $role }} Code: {{ $aoCode }}</div>
            </td>

            <td class="p-3 text-right font-bold">
              {{ number_format((float)($r->score_total ?? 0), 2) }}
            </td>

            @if($role==='RO')
              <td class="p-3 text-right">{{ $fmtPct($r->repayment_pct ?? 0, 2) }}</td>
              <td class="p-3 text-right">{{ $fmtRp($r->topup_realisasi ?? 0) }}</td>
              <td class="p-3 text-right">{{ number_format((int)($r->noa_realisasi ?? 0)) }}</td>
              <td class="p-3 text-right">{{ $fmtPct($r->dpk_pct ?? 0, 4) }}</td>
              <td class="p-3 text-right">
                <div>{{ number_format((int)($r->dpk_migrasi_count ?? 0)) }}</div>
                <div class="text-xs text-slate-500">{{ $fmtRp($r->dpk_migrasi_os ?? 0) }}</div>
              </td>

            @elseif($role==='AO')
                <td class="p-3 text-right">{{ $fmtRp($r->os_disb ?? 0) }}</td>
                <td class="p-3 text-right">{{ number_format((int)($r->noa_disb ?? 0)) }}</td>
                <td class="p-3 text-right">{{ $fmtPct($r->rr_pct ?? 0, 2) }}</td>
                <td class="p-3 text-right">{{ number_format((float)($r->score_os ?? 0), 2) }}</td>
                <td class="p-3 text-right">{{ number_format((float)($r->score_noa ?? 0), 2) }}</td>

            @elseif($role==='SO')
              <td class="p-3 text-right">{{ $fmtRp($r->os_disb ?? 0) }}</td>
              <td class="p-3 text-right">{{ number_format((int)($r->noa_disb ?? 0)) }}</td>
              <td class="p-3 text-right">{{ $fmtPct($r->rr_pct ?? 0, 2) }}</td>
              <td class="p-3 text-right">{{ number_format((float)($r->score_os ?? 0), 2) }}</td>
              <td class="p-3 text-right">{{ number_format((float)($r->score_noa ?? 0), 2) }}</td>

            @elseif($role==='FE')
              <td class="p-3 text-right text-slate-500">Score FE terisi, detail metrik akan kita tampilkan setelah field FE di-expose di query.</td>

            @elseif($role==='BE')
              <td class="p-3 text-right text-slate-500">Score BE terisi, detail metrik akan kita tampilkan setelah field BE di-expose di query.</td>

            @else
              <td class="p-3 text-right">{{ $fmtRp($r->os_disb ?? 0) }}</td>
              <td class="p-3 text-right">{{ number_format((int)($r->noa_disb ?? 0)) }}</td>
            @endif
          </tr>

        @empty
          <tr>
            <td colspan="12" class="p-6 text-center text-slate-500">
              Data belum tersedia.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
