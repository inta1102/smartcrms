@foreach($tlRecap->rankings as $r)
    @php
    $isTop = ((int)($r->rank ?? 99) <= 3);
    $rowClass = $isTop ? 'bg-emerald-50/40' : '';
    @endphp
    <tr class="{{ $rowClass }}">
    <td class="px-3 py-2 font-bold text-slate-900">{{ $r->rank }}</td>
    <td class="px-3 py-2">
        <div class="font-semibold text-slate-900">{{ $r->name }}</div>
        <div class="text-xs text-slate-500">
        Ach: OS {{ $fmtPct($r->ach_os ?? 0) }} • Mg {{ $fmtPct($r->ach_mg ?? 0) }} • Denda {{ $fmtPct($r->ach_pen ?? 0) }}
        </div>
    </td>
    <td class="px-3 py-2 font-mono text-slate-700">{{ $r->ao_code }}</td>
    <td class="px-3 py-2 text-right">{{ number_format((float)($r->pi_os ?? 0), 2) }}</td>
    <td class="px-3 py-2 text-right">{{ number_format((float)($r->pi_mg ?? 0), 2) }}</td>
    <td class="px-3 py-2 text-right">{{ number_format((float)($r->pi_pen ?? 0), 2) }}</td>
    <td class="px-3 py-2 text-right font-extrabold text-slate-900">{{ number_format((float)($r->pi_total ?? 0), 2) }}</td>
    </tr>
@endforeach