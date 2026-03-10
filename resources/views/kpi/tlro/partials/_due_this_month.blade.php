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

  $todayDate     = now()->startOfDay()->toDateString();
  $tomorrowDate  = now()->copy()->addDay()->startOfDay()->toDateString();
  $plus2Date     = now()->copy()->addDays(2)->startOfDay()->toDateString();

  $dueHighlight = $dueHighlight ?? function($maturityDate) use ($todayDate, $tomorrowDate, $plus2Date) {
    if (empty($maturityDate)) {
      return [
        'rowClass'  => '',
        'cellClass' => '',
        'badge'     => '',
      ];
    }

    try {
      $dueDate = \Carbon\Carbon::parse($maturityDate)->startOfDay()->toDateString();

      if ($dueDate === $todayDate) {
        return [
          'rowClass'  => 'bg-rose-100',
          'cellClass' => 'bg-rose-100 text-rose-900',
          'badge'     => '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold bg-rose-600 text-white">Hari Ini</span>',
        ];
      }

      if ($dueDate === $tomorrowDate) {
        return [
          'rowClass'  => 'bg-orange-100',
          'cellClass' => 'bg-orange-100 text-orange-900',
          'badge'     => '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold bg-orange-500 text-white">H-1</span>',
        ];
      }

      if ($dueDate === $plus2Date) {
        return [
          'rowClass'  => 'bg-yellow-100',
          'cellClass' => 'bg-yellow-100 text-yellow-900',
          'badge'     => '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-bold bg-yellow-400 text-yellow-900">H-2</span>',
        ];
      }

      return [
        'rowClass'  => '',
        'cellClass' => '',
        'badge'     => '',
      ];
    } catch (\Throwable $e) {
      return [
        'rowClass'  => '',
        'cellClass' => '',
        'badge'     => '',
      ];
    }
  };
@endphp

<div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
  <div class="p-4 border-b border-slate-200">
    <div class="font-bold text-slate-900">Debitur Jatuh Tempo – {{ $dueMonthLabel ?? now()->translatedFormat('F Y') }}</div>
    <div class="text-xs text-slate-500 mt-1">
      Sumber: maturity_date (tgl_jto). Scope mengikuti bawahan TL.
    </div>

    <div class="mt-3 flex flex-wrap gap-2 text-xs">
      <span class="inline-flex items-center rounded-full px-2.5 py-1 font-semibold bg-rose-100 text-rose-800 border border-rose-200">
        Merah = Jatuh tempo hari ini
      </span>
      <span class="inline-flex items-center rounded-full px-2.5 py-1 font-semibold bg-orange-100 text-orange-800 border border-orange-200">
        Oranye = H-1
      </span>
      <span class="inline-flex items-center rounded-full px-2.5 py-1 font-semibold bg-yellow-100 text-yellow-800 border border-yellow-200">
        Kuning = H-2
      </span>
    </div>
  </div>

  <div class="p-4 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr class="text-slate-700">
          <th class="text-left px-3 py-2">Jatuh Tempo</th>
          <th class="text-left px-3 py-2">Nama Debitur</th>
          <th class="text-left px-3 py-2">AO</th>
          <th class="text-right px-3 py-2">OS</th>
          <th class="text-right px-3 py-2">DPD</th>
          <th class="text-right px-3 py-2">Kolek</th>
          <th class="text-left px-3 py-2 whitespace-nowrap">Risk</th>
          <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit Terakhir</th>
          <th class="text-left px-3 py-2 whitespace-nowrap">Hasil Kunjungan</th>
          <th class="text-right px-3 py-2 whitespace-nowrap">Umur Visit</th>
          <th class="text-center px-3 py-2 whitespace-nowrap">Plan Visit</th>
          <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan Visit</th>
        </tr>
      </thead>

      <tbody class="divide-y divide-slate-200">
        @forelse(($dueThisMonth ?? []) as $r)
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
            $os  = (int)($r->outstanding ?? 0);

            $baseEmphasis = ($os >= (int)($bigThreshold ?? 500000000) || (int)($r->dpd ?? 0) >= 8)
              ? 'bg-amber-50/30'
              : '';

            $dueUi = $dueHighlight($r->maturity_date ?? null);

            // warna jatuh tempo lebih prioritas
            $cellBgClass = $dueUi['cellClass'] ?: $baseEmphasis;
          @endphp

          <tr>
            <td class="px-3 py-2 whitespace-nowrap {{ $cellBgClass }}">
              <div class="flex items-center gap-2">
                <span class="{{ !empty($dueUi['cellClass']) ? 'font-bold' : '' }}">
                  {{ !empty($r->maturity_date) ? \Carbon\Carbon::parse($r->maturity_date)->format('d/m/Y') : '-' }}
                </span>
                @if(!empty($dueUi['badge']))
                  {!! $dueUi['badge'] !!}
                @endif
              </div>
            </td>

            <td class="px-3 py-2 {{ $cellBgClass }}">{{ $r->customer_name ?? '-' }}</td>
            <td class="px-3 py-2 font-mono {{ $cellBgClass }}">{{ $r->ao_code ?? '-' }}</td>
            <td class="px-3 py-2 text-right {{ $cellBgClass }}">Rp {{ number_format($os,0,',','.') }}</td>
            <td class="px-3 py-2 text-right {{ $cellBgClass }}">{{ (int)($r->dpd ?? 0) }}</td>
            <td class="px-3 py-2 text-right {{ $cellBgClass }}">{{ $r->kolek ?? '-' }}</td>
            <td class="px-3 py-2 whitespace-nowrap {{ $cellBgClass }}">{!! $riskBadge($r->dpd ?? 0, $r->kolek ?? '-', false) !!}</td>
            <td class="px-3 py-2 whitespace-nowrap {{ $cellBgClass }}">{{ $lastVisit }}</td>

            <td class="px-4 py-3 text-sm text-slate-700 {{ $cellBgClass }}">
              <div title="{{ $r->hasil_kunjungan ?? '-' }}">
                {{ \Illuminate\Support\Str::limit($r->hasil_kunjungan ?? '-', 60) }}
              </div>
            </td>

            <td class="px-3 py-2 text-right whitespace-nowrap {{ $cellBgClass }}">
              @if($age === null)
                <span class="text-slate-400">-</span>
              @else
                <span class="{{ $age >= 14 ? 'text-rose-700 font-semibold' : ($age >= 7 ? 'text-amber-700 font-semibold' : 'text-slate-700') }}">
                  {{ number_format(floor($age),0,',','.') }} hari
                </span>
              @endif
            </td>

            <td class="px-3 py-2 text-center whitespace-nowrap {{ $cellBgClass }}">
              @if($isDone)
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">Done</span>
              @else
                <button type="button"
                  class="btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border {{ $planned ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-800 border-slate-200' }}"
                  data-acc="{{ $acc }}"
                  data-ao="{{ $ao }}"
                  data-checked="{{ $planned ? '1' : '0' }}">
                  {{ $planned ? 'Unplan' : 'Plan' }}
                </button>
              @endif
            </td>

            <td class="px-3 py-2 whitespace-nowrap {{ $cellBgClass }}">
              <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="12" class="px-3 py-6 text-center text-slate-500">
              Belum ada data jatuh tempo bulan ini.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>