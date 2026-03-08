<div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
  <div class="p-4 border-b border-slate-200">
    <div class="font-bold text-slate-900">Disiplin Kunjungan RO</div>
    <div class="text-xs text-slate-500 mt-1">
      Menampilkan jumlah debitur existing pada RKH approved yang sudah DONE, masih PLAN, dan belum dikunjungi pada periode terpilih.
    </div>
  </div>

  <div class="p-4 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr class="text-slate-700">
          <th class="text-left px-3 py-2">RO</th>
          <th class="text-left px-3 py-2">AO</th>
          <th class="text-right px-3 py-2">Total Debitur</th>
          <th class="text-right px-3 py-2">Visit DONE</th>
          <th class="text-right px-3 py-2">Plan Saja</th>
          <th class="text-right px-3 py-2">Belum Dikunjungi</th>
          <th class="text-right px-3 py-2">Progress</th>
        </tr>
      </thead>

      <tbody class="divide-y divide-slate-200">
        @forelse(($visitDiscipline ?? []) as $r)
          @php
            $total = (int)($r->total_rkh ?? 0);
            $done  = (int)($r->done_visit ?? 0);
            $plan  = (int)($r->planned_only ?? 0);
            $miss  = (int)($r->belum_dikunjungi ?? 0);
            $pct = $total > 0 ? round(($done / $total) * 100, 2) : 0;

            $tone = 'text-emerald-700 bg-emerald-50 border-emerald-200';
            if ($miss >= 5) {
              $tone = 'text-rose-700 bg-rose-50 border-rose-200';
            } elseif ($miss >= 2) {
              $tone = 'text-amber-700 bg-amber-50 border-amber-200';
            }
          @endphp

          <tr>
            <td class="px-3 py-2 font-semibold text-slate-900">{{ $r->ro_name ?? '-' }}</td>
            <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
            <td class="px-3 py-2 text-right">{{ number_format($total, 0, ',', '.') }}</td>
            <td class="px-3 py-2 text-right text-emerald-700 font-semibold">{{ number_format($done, 0, ',', '.') }}</td>
            <td class="px-3 py-2 text-right text-sky-700 font-semibold">{{ number_format($plan, 0, ',', '.') }}</td>
            <td class="px-3 py-2 text-right">
              <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold {{ $tone }}">
                {{ number_format($miss, 0, ',', '.') }}
              </span>
            </td>
            <td class="px-3 py-2 text-right font-semibold">
              {{ number_format($pct, 2, ',', '.') }}%
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="7" class="px-3 py-6 text-center text-slate-500">
              Belum ada data disiplin kunjungan pada periode ini.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>