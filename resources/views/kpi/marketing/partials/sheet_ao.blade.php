<div class="space-y-6">
  @foreach($items as $it)
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-3">
        <div>
          <div class="text-lg font-extrabold text-slate-900 uppercase">{{ $it->name }}</div>
          <div class="text-xs text-slate-500">
            {{ $it->level }} • AO Code: <b>{{ $it->ao_code ?: '-' }}</b>
          </div>
        </div>
        <div class="text-right">
          <div class="text-xs text-slate-500">Total PI</div>
          <div class="text-xl font-extrabold text-slate-900">
            {{ number_format((float)($it->pi_total ?? 0), 2) }}
          </div>
        </div>
      </div>

      <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-orange-500 text-white">
            <tr>
              <th class="text-left px-3 py-2">KPI</th>
              <th class="text-right px-3 py-2">Target</th>
              <th class="text-right px-3 py-2">Actual</th>
              <th class="text-right px-3 py-2">Pencapaian</th>
              <th class="text-center px-3 py-2">Skor</th>
              <th class="text-right px-3 py-2">Bobot</th>
              <th class="text-right px-3 py-2">PI</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-200">
            <tr>
              <td class="px-3 py-2 font-semibold">Target OS Growth</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format((int)($it->target_os_growth ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format((int)($it->os_growth ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_os ?? 0),2) }}%</td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_os ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['os'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_os ?? 0),2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">Target NOA Growth</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->target_noa_growth ?? 0),2) }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->noa_growth ?? 0),2) }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_noa ?? 0),2) }}%</td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_noa ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['noa'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_noa ?? 0),2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">Repayment Rate</td>
              <td class="px-3 py-2 text-right">100%</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->rr_pct ?? 0),2) }}%</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_rr ?? 0),2) }}%</td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_rr ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['rr'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_rr ?? 0),2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">Kualitas Kolek / Migrasi NPL</td>
              <td class="px-3 py-2 text-right">-</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->npl_migration_pct ?? 0),2) }}%</td>
              <td class="px-3 py-2 text-right">-</td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_kolek ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['kolek'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_kolek ?? 0),2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">Activity</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->target_activity ?? 0),2) }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->activity_actual ?? 0),2) }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_activity ?? 0),2) }}%</td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_activity ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['activity'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_activity ?? 0),2) }}</td>
            </tr>
          </tbody>

          <tfoot>
            <tr class="bg-yellow-200">
              <td colspan="6" class="px-3 py-2 font-extrabold text-right">TOTAL</td>
              <td class="px-3 py-2 font-extrabold text-right">
                {{ number_format((float)($it->pi_total ?? 0),2) }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  @endforeach
</div>
