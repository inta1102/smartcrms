@php
  $fmtDate = $fmtDate ?? function($v) {
    return !empty($v) ? \Carbon\Carbon::parse($v)->format('d/m/Y') : '-';
  };

  $visitAgeDays = $visitAgeDays ?? function($lastVisit) {
    if (empty($lastVisit)) return null;
    try {
      return \Carbon\Carbon::parse($lastVisit)->diffInDays(now());
    } catch (\Throwable $e) {
      return null;
    }
  };

  $riskBadge = $riskBadge ?? function($dpd, $kolek, $isLt = false) {
    $dpd = (int)($dpd ?? 0);
    $kolek = (string)($kolek ?? '');
    $kolekNum = is_numeric($kolek) ? (int)$kolek : null;

    if ($isLt) {
      return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">LT</span>';
    }

    if ($dpd >= 30 || ($kolekNum !== null && $kolekNum >= 3)) {
      return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">High</span>';
    }

    if ($dpd >= 8 || ($kolekNum !== null && $kolekNum === 2)) {
      return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">Medium</span>';
    }

    return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">Low</span>';
  };
@endphp

<div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
  <div class="p-4 border-b border-slate-200">
    <div class="font-bold text-slate-900">
      JT Angsuran Minggu Ini
      @if(!empty($weekStart) && !empty($weekEnd))
        <span class="text-slate-500 font-normal text-sm">({{ $weekStart }} s/d {{ $weekEnd }})</span>
      @endif
    </div>
    <div class="text-xs text-slate-500 mt-1">
      Sumber: installment_day.
    </div>
  </div>

  <div class="p-4 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr class="text-slate-700">
          <th class="text-left px-3 py-2">JT (Tanggal)</th>
          <th class="text-left px-3 py-2">Nama Debitur</th>
          <th class="text-left px-3 py-2">AO</th>
          <th class="text-right px-3 py-2">OS</th>
          <th class="text-right px-3 py-2">FT Pokok</th>
          <th class="text-right px-3 py-2">FT Bunga</th>
          <th class="text-right px-3 py-2">DPD</th>
          <th class="text-right px-3 py-2">Kolek</th>
          <th class="text-left px-3 py-2 whitespace-nowrap">Risk</th>
          <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
          <th class="text-right px-3 py-2 whitespace-nowrap">Umur Visit</th>
          <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit</th>
          <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
        </tr>
      </thead>

      <tbody class="divide-y divide-slate-200">
        @forelse(($jtAngsuran ?? []) as $r)
          @php
            $lastVisitRaw = $r->last_visit_date ?? null;
            $lastVisit = $fmtDate($lastVisitRaw);
            $age = $visitAgeDays($lastVisitRaw);

            $planned = (int)($r->planned_today ?? $r->visit_today ?? 0) === 1;
            $status  = (string)($r->plan_status ?? '');
            $isDone  = $planned && $status === 'done';

            $planVisit = $fmtDate($r->plan_visit_date ?? null);
            $acc = (string)($r->account_no ?? '');
            $ao  = (string)($r->ao_code ?? '');
            $os  = (int)($r->os ?? 0);
          @endphp

          <tr>
            <td class="px-3 py-2 whitespace-nowrap">
              {{ !empty($r->due_date) ? \Carbon\Carbon::parse($r->due_date)->format('d/m/Y') : '-' }}
            </td>
            <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
            <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
            <td class="px-3 py-2 text-right">Rp {{ number_format($os,0,',','.') }}</td>
            <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ (int)($r->kolek ?? 0) }}</td>
            <td class="px-3 py-2 whitespace-nowrap">{!! $riskBadge($r->dpd ?? 0, $r->kolek ?? '-', false) !!}</td>
            <td class="px-3 py-2 whitespace-nowrap">{{ $lastVisit }}</td>

            <td class="px-3 py-2 text-right whitespace-nowrap">
              @if($age === null)
                <span class="text-slate-400">-</span>
              @else
                <span class="{{ $age >= 14 ? 'text-rose-700 font-semibold' : ($age >= 7 ? 'text-amber-700 font-semibold' : 'text-slate-700') }}">
                  {{ $age }} hari
                </span>
              @endif
            </td>

            <td class="px-3 py-2 text-center whitespace-nowrap">
              @if($isDone)
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                  Done
                </span>
              @else
                <button type="button"
                  class="btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border {{ $planned ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-800 border border-slate-200' }}"
                  data-acc="{{ $acc }}"
                  data-ao="{{ $ao }}"
                  data-checked="{{ $planned ? '1' : '0' }}">
                  {{ $planned ? 'Unplan' : 'Plan' }}
                </button>
              @endif
            </td>

            <td class="px-3 py-2 whitespace-nowrap">
              <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="13" class="px-3 py-6 text-center text-slate-500">
              Tidak ada JT angsuran minggu ini.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>