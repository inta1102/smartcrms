<div class="space-y-6">
  @foreach($items as $it)
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-3">
        <div>
          <div class="text-lg font-extrabold text-slate-900 uppercase">{{ $it->name }}</div>
          <div class="text-xs text-slate-500">
            RO • AO Code: <b>{{ $it->ao_code ?: '-' }}</b>
            @if(($it->baseline_ok ?? 0) == 1)
              <span class="ml-2 inline-flex rounded-full bg-emerald-100 text-emerald-800 px-3 py-1 text-xs font-semibold">
                BASELINE OK
              </span>
            @endif
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
            @once
                @php
                $kpiBadgeNormal = function ($ach) {
                    $ach = (float)($ach ?? 0);
                    if ($ach >= 100) return 'bg-emerald-100 text-emerald-800 border border-emerald-200';
                    if ($ach >= 80)  return 'bg-amber-100 text-amber-800 border border-amber-200';
                    return 'bg-rose-100 text-rose-800 border border-rose-200';
                };

                $kpiBadgeReverse = function ($actual, $target) {
                    $actual = (float)($actual ?? 0);
                    $target = (float)($target ?? 0);
                    if ($target <= 0) return 'bg-slate-100 text-slate-600 border border-slate-200';

                    $ratio = ($actual / $target) * 100.0; // <=100 bagus
                    if ($ratio <= 100) return 'bg-emerald-100 text-emerald-800 border border-emerald-200';
                    if ($ratio <= 120) return 'bg-amber-100 text-amber-800 border border-amber-200';
                    return 'bg-rose-100 text-rose-800 border border-rose-200';
                };
                @endphp
            @endonce

            {{-- Repayment --}}
            <tr>
                <td class="px-3 py-2 font-semibold">Repayment Rate</td>

                {{-- Target RR --}}
                <td class="px-3 py-2 text-right">
                    {{ number_format((float)($it->target_rr_pct ?? 100), 2) }}%
                </td>

                {{-- Actual RR --}}
                <td class="px-3 py-2 text-right">
                    {{ number_format((float)($it->repayment_pct_display ?? 0), 2) }}%
                </td>

                {{-- Pencapaian RR (Actual/Target) --}}
                <td class="px-3 py-2 text-right">  
                    <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($it->ach_rr ?? 0) }}">
                        {{ number_format((float)($it->ach_rr ?? 0),2) }}%
                    </span>
                </td>


                <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->repayment_score ?? 0), 2) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)round(($weights['repayment'] ?? 0)*100) }}%</td>
                <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_repayment ?? 0), 2) }}</td>
            </tr>

            {{-- TopUp --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Target Realisasi TU (Top Up)</td>
              <td class="px-3 py-2 text-right">
                Rp {{ number_format((int)($it->target_topup ?? 0),0,',','.') }}
              </td>
              <td class="px-3 py-2 text-right">
                Rp {{ number_format((int)($it->topup_realisasi ?? 0),0,',','.') }}
              </td>
              <td class="px-3 py-2 text-right">
                <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($it->ach_rr ?? 0) }}">
                    {{ number_format((float)($it->ach_topup ?? 0),2) }}%
                </span>
              </td>

              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->topup_score ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['topup'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_topup ?? 0), 2) }}</td>
            </tr>

            {{-- NOA --}}
            <tr>
              <td class="px-3 py-2 font-semibold">NOA Pengembangan</td>
              <td class="px-3 py-2 text-right">{{ number_format((int)($it->target_noa ?? 0)) }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((int)($it->noa_realisasi ?? 0)) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $kpiBadgeNormal($it->ach_rr ?? 0) }}">
                    {{ number_format((float)($it->ach_noa ?? 0),2) }}%
                </span>
              </td>

              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->noa_score ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['noa'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_noa ?? 0), 2) }}</td>
            </tr>

           {{-- DPK --}}
            @php
                $tDpk = (float)($it->target_dpk_pct ?? 0);  // target dalam % (contoh 1.00 artinya 1%)
                $aDpk = (float)($it->dpk_pct ?? 0);         // actual dalam % (contoh 7.33)

                // Pencapaian (lower is better):
                // - kalau actual = 0 => 100% (perfect)
                // - kalau target = 0 => 0 (tidak bisa dihitung)
                // - kalau actual <= target => >=100 (bagus)
                $achDpk = 0.0;
                if ($tDpk > 0) {
                    $achDpk = ($aDpk <= 0) ? 100.0 : round(($tDpk / $aDpk) * 100.0, 2);
                }
            @endphp

            <tr>
            <td class="px-3 py-2 font-semibold">Pemburukan DPK (Migrasi)</td>

            {{-- TARGET --}}
            <td class="px-3 py-2 text-right">
                {{ number_format((float)($it->target_dpk_pct ?? 0), 2) }}%
            </td>

            {{-- ACTUAL --}}
            <td class="px-3 py-2 text-right">
                {{ number_format((float)($it->dpk_pct ?? 0), 2) }}%
            </td>

            {{-- PENCAPAIAN --}}
            <td class="px-3 py-2 text-right">
                <span class="px-3 py-1 rounded-full text-xs font-semibold 
                    {{ $kpiBadgeReverse($it->dpk_pct ?? 0, $it->target_dpk_pct ?? 1) }}">
                    {{ number_format((float)($it->ach_dpk ?? 0),2) }}%
                </span>
            </td>

            {{-- SKOR --}}
            <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->dpk_score ?? 0), 2) }}</td>

            {{-- BOBOT --}}
            <td class="px-3 py-2 text-right">{{ (int)round(($weights['dpk'] ?? 0)*100) }}%</td>

            {{-- PI --}}
            <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_dpk ?? 0), 2) }}</td>
            </tr>

            <tr class="bg-slate-50">
                <td class="px-3 py-2 text-slate-600">Detail DPK Migrasi</td>
                <td class="px-3 py-2 text-slate-600 text-center">Count</td>
                <td class="px-3 py-2 text-slate-600 text-right">{{ number_format((int)($it->dpk_migrasi_count ?? 0)) }}</td>
                <td class="px-3 py-2 text-slate-600 text-center">OS Migrasi</td>
                <td class="px-3 py-2 text-slate-600 text-right" colspan="3">
                    Rp {{ number_format((int)($it->dpk_migrasi_os ?? 0),0,',','.') }}
                </td>
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

    @if(!empty($tlRecap))
      @php
        $lr = strtoupper($tlRecap->leader_role ?? 'LEADER');
      @endphp

      <div class="mt-8 rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        {{-- Header --}}
        <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-4">
          <div>
            <div class="text-lg font-extrabold text-slate-900 uppercase">
              KPI {{ $lr }} – {{ $tlRecap->name }}
            </div>

            <div class="text-xs text-slate-500 mt-1 space-y-0.5">
              @if(!empty($tlRecap->scope_count))
                <div>Scope RO: <span class="font-semibold text-slate-700">{{ (int)$tlRecap->scope_count }}</span></div>
              @endif
              <div>
                Catatan: Repayment Rate menggunakan <span class="font-semibold text-slate-700">OS-Weighted</span>
                (bobot OS akhir / exposure).
              </div>
            </div>
          </div>

          <div class="text-right">
            <div class="text-xs text-slate-500">TOTAL PI {{ $lr }}</div>
            <div class="text-2xl font-extrabold text-slate-900">
              {{ number_format((float)$tlRecap->pi_total, 2) }}
            </div>
          </div>
        </div>

        {{-- Table --}}
        <div class="p-4 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-900 text-white">
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
                <td class="px-3 py-2 font-semibold">
                  Repayment Rate (OS-Weighted Scope)
                </td>
                <td class="px-3 py-2 text-right">{{ number_format((float)$tlRecap->target_rr_pct, 2) }}%</td>
                <td class="px-3 py-2 text-right">{{ number_format((float)$tlRecap->rr_actual_avg, 2) }}%</td>
                <td class="px-3 py-2 text-right">{{ number_format((float)$tlRecap->ach_rr, 2) }}%</td>
                <td class="px-3 py-2 text-center font-bold">{{ number_format((float)$tlRecap->score_repayment, 2) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)round(((float)($weights['repayment'] ?? 0)) * 100) }}%</td>
                <td class="px-3 py-2 text-right font-bold">{{ number_format((float)$tlRecap->pi_repayment, 2) }}</td>
              </tr>

              <tr>
                <td class="px-3 py-2 font-semibold">Target Realisasi TU (Total Scope)</td>
                <td class="px-3 py-2 text-right">Rp {{ number_format((float)$tlRecap->target_topup_total, 0, ',', '.') }}</td>
                <td class="px-3 py-2 text-right">Rp {{ number_format((float)$tlRecap->topup_actual_total, 0, ',', '.') }}</td>
                <td class="px-3 py-2 text-right">{{ number_format((float)$tlRecap->ach_topup, 2) }}%</td>
                <td class="px-3 py-2 text-center font-bold">{{ number_format((float)$tlRecap->score_topup, 2) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)round(((float)($weights['topup'] ?? 0)) * 100) }}%</td>
                <td class="px-3 py-2 text-right font-bold">{{ number_format((float)$tlRecap->pi_topup, 2) }}</td>
              </tr>

              <tr>
                <td class="px-3 py-2 font-semibold">NOA Pengembangan (Total Scope)</td>
                <td class="px-3 py-2 text-right">{{ number_format((int)$tlRecap->target_noa_total) }}</td>
                <td class="px-3 py-2 text-right">{{ number_format((int)$tlRecap->noa_actual_total) }}</td>
                <td class="px-3 py-2 text-right">{{ number_format((float)$tlRecap->ach_noa, 2) }}%</td>
                <td class="px-3 py-2 text-center font-bold">{{ number_format((float)$tlRecap->score_noa, 2) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)round(((float)($weights['noa'] ?? 0)) * 100) }}%</td>
                <td class="px-3 py-2 text-right font-bold">{{ number_format((float)$tlRecap->pi_noa, 2) }}</td>
              </tr>

              <tr>
                <td class="px-3 py-2 font-semibold">Pemburukan DPK (Scope)</td>
                <td class="px-3 py-2 text-right">{{ number_format((float)$tlRecap->target_dpk_pct, 2) }}%</td>
                <td class="px-3 py-2 text-right">{{ number_format((float)$tlRecap->dpk_actual_pct, 2) }}%</td>
                <td class="px-3 py-2 text-right">{{ number_format((float)$tlRecap->ach_dpk, 2) }}%</td>
                <td class="px-3 py-2 text-center font-bold">{{ number_format((float)$tlRecap->score_dpk, 2) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)round(((float)($weights['dpk'] ?? 0)) * 100) }}%</td>
                <td class="px-3 py-2 text-right font-bold">{{ number_format((float)$tlRecap->pi_dpk, 2) }}</td>
              </tr>
            </tbody>

            <tfoot>
              <tr class="bg-yellow-200">
                <td colspan="6" class="px-3 py-2 font-extrabold text-right">TOTAL PI {{ $lr }}</td>
                <td class="px-3 py-2 font-extrabold text-right">{{ number_format((float)$tlRecap->pi_total, 2) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    @endif

</div>
