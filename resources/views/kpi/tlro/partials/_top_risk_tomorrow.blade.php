<div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
  <div class="p-4 border-b border-slate-200 flex flex-col md:flex-row md:items-center md:justify-between gap-2">
    <div>
      <div class="font-extrabold text-slate-900">🔥 Top Risiko Besok</div>
      <div class="text-xs text-slate-500 mt-1">
        Prioritas: Cure LT→L0 yang JT dekat dan JT dekat dengan DPD&gt;0 / FT.
      </div>
    </div>
    <div class="text-xs text-slate-500">
      Range JT: <b>{{ $jtNext2Start && $jtNext2End ? ($jtNext2Start.' s/d '.$jtNext2End) : '-' }}</b>
    </div>
  </div>

  <div class="p-4 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr class="text-slate-700">
          <th class="text-left px-3 py-2">Due Date</th>
          <th class="text-left px-3 py-2">No Rek</th>
          <th class="text-left px-3 py-2">Nama</th>
          <th class="text-left px-3 py-2">AO</th>
          <th class="text-right px-3 py-2">OS</th>
          <th class="text-right px-3 py-2">DPD</th>
          <th class="text-right px-3 py-2">Kolek</th>
          <th class="text-center px-3 py-2">FT?</th>
          <th class="text-left px-3 py-2">Alasan</th>
          <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Visit</th>
          <th class="text-right px-3 py-2 whitespace-nowrap">Umur</th>
          <th class="text-center px-3 py-2 whitespace-nowrap">Plan</th>
          <th class="text-left px-3 py-2 whitespace-nowrap">Tgl Plan</th>
        </tr>
      </thead>

      <tbody class="divide-y divide-slate-200">
        @forelse(($topRiskTomorrow ?? []) as $r)
          @php
            $lastVisitRaw = $r->last_visit_date ?? null;
            $lastVisit = !empty($lastVisitRaw) ? \Carbon\Carbon::parse($lastVisitRaw)->format('d/m/Y') : '-';
            $age = null;
            try { $age = !empty($lastVisitRaw) ? \Carbon\Carbon::parse($lastVisitRaw)->diffInDays(now()) : null; } catch (\Throwable $e) { $age = null; }

            $planned = (int)($r->planned_today ?? 0) === 1;
            $status  = (string)($r->plan_status ?? '');
            $isDone  = $planned && $status === 'done';

            $planVisitDateRaw = $r->plan_visit_date ?? null;
            $planVisit = !empty($planVisitDateRaw) ? \Carbon\Carbon::parse($planVisitDateRaw)->format('d/m/Y') : '-';

            $acc = (string)($r->account_no ?? '');
            $ao  = (string)($r->ao_code ?? '');
            $os  = (int)($r->os ?? 0);

            $rowCls = str_contains((string)($r->risk_reason ?? ''), 'Cure') ? 'bg-amber-50/40' : 'bg-white';
            if ((int)($r->dpd ?? 0) >= 8) $rowCls = 'bg-rose-50/35';

            $due = !empty($r->due_date) ? \Carbon\Carbon::parse($r->due_date)->format('d/m/Y') : '-';
          @endphp

          <tr class="{{ $rowCls }}">
            <td class="px-3 py-2 whitespace-nowrap">
              <span class="font-semibold text-slate-900">{{ $due }}</span>
            </td>
            <td class="px-3 py-2 font-mono">{{ $acc }}</td>
            <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
            <td class="px-3 py-2 font-mono">{{ $ao }}</td>
            <td class="px-3 py-2 text-right font-semibold text-slate-900">Rp {{ number_format($os,0,',','.') }}</td>
            <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
            <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>
            <td class="px-3 py-2 text-center">
              @if((int)($r->ft_flag ?? 0) === 1)
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">FT</span>
              @else
                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold bg-slate-50 text-slate-700 border border-slate-200">-</span>
              @endif
            </td>
            <td class="px-3 py-2">
              <span class="text-xs font-semibold text-slate-800">{{ $r->risk_reason ?? '-' }}</span>
            </td>
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
            <td class="px-3 py-2 whitespace-nowrap">
              <span class="ro-plan-date" data-account="{{ $acc }}">{{ $planVisit }}</span>
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="13" class="px-3 py-6 text-center text-slate-500">
              Tidak ada kandidat “Top Risiko Besok”.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>