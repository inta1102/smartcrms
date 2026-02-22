@php
  $fmtRp  = fn($n) => 'Rp ' . number_format((int)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n) => number_format((float)($n ?? 0), 2) . '%';

  // RR Badge (SO)
  $rrBadge = function($rr) {
    $rr = (float)($rr ?? 0);
    if ($rr >= 90) return ['label'=>'AMAN', 'cls'=>'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200'];
    if ($rr >= 80) return ['label'=>'WASPADA', 'cls'=>'bg-yellow-100 text-yellow-800 ring-1 ring-yellow-200'];
    return ['label'=>'RISIKO', 'cls'=>'bg-rose-100 text-rose-700 ring-1 ring-rose-200'];
  };

  // untuk badge Akumulasi
  $accLabel = null;
  if (!empty($startYtd) && !empty($endYtd)) {
    try {
      $accLabel = 'Akumulasi ' .
        \Carbon\Carbon::parse($startYtd)->translatedFormat('d M Y') .
        ' – ' .
        \Carbon\Carbon::parse($endYtd)->translatedFormat('d M Y');
    } catch (\Throwable $e) {
      $accLabel = 'Akumulasi YTD';
    }
  }
@endphp

<div class="space-y-6">
  @foreach($items as $it)
    @php
      $osRaw = $it->os_disbursement_raw ?? null;
      $osAdj = $it->os_adjustment ?? null;

      $hasOsMeta = !is_null($osRaw) || !is_null($osAdj);
      $osAdjVal  = (int)($osAdj ?? 0);

      $rr = (float)($it->rr_pct ?? 0);
      $rrB = $rrBadge($rr);

      // quick headline numbers
      $headlineOs = (int)($it->os_disbursement ?? 0);
      $headlineNoa = (float)($it->noa_disbursement ?? 0);
      $headlineAct = (float)($it->activity_actual ?? 0);

      // achievement
      $achOs  = (float)($it->ach_os ?? 0);
      $achNoa = (float)($it->ach_noa ?? 0);
      $achRr  = (float)($it->ach_rr ?? 0);
      $achAct = (float)($it->ach_activity ?? 0);

      // skor
      $sOs  = (int)($it->score_os ?? 0);
      $sNoa = (int)($it->score_noa ?? 0);
      $sRr  = (int)($it->score_rr ?? 0);
      $sAct = (int)($it->score_activity ?? 0);

      // pi
      $piTot = (float)($it->pi_total ?? 0);
    @endphp

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      {{-- HEADER --}}
      <div class="px-5 py-4 border-b border-slate-200 flex flex-col md:flex-row md:items-start md:justify-between gap-3">
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <div class="text-lg font-extrabold text-slate-900 uppercase">{{ $it->name }}</div>

            {{-- Badge Akumulasi --}}
            @if(!empty($accLabel))
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-indigo-50 text-indigo-700 ring-1 ring-indigo-200">
                {{ $accLabel }}
              </span>
            @endif

            {{-- RR Badge --}}
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] {{ $rrB['cls'] }}">
              RR {{ $rrB['label'] }} • {{ number_format($rr,2) }}%
            </span>

            {{-- OS adjusted badge --}}
            @if(($it->os_adjustment ?? 0) > 0)
              <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] bg-amber-100 text-amber-800 ring-1 ring-amber-200">
                OS adjusted
              </span>
            @endif
          </div>

          <div class="text-xs text-slate-500 mt-1">
            {{ $it->level }} • AO Code: <b>{{ $it->ao_code ?: '-' }}</b>
          </div>

          {{-- Quick metrics row --}}
          <div class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-2">
            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
              <div class="text-[11px] text-slate-500">OS (Actual)</div>
              <div class="text-sm font-extrabold text-slate-900">{{ $fmtRp($headlineOs) }}</div>
              <div class="text-[11px] text-slate-500">Ach: <b>{{ $fmtPct($achOs) }}</b> • Skor <b>{{ $sOs }}</b></div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
              <div class="text-[11px] text-slate-500">NOA (Actual)</div>
              <div class="text-sm font-extrabold text-slate-900">{{ number_format($headlineNoa,2) }}</div>
              <div class="text-[11px] text-slate-500">Ach: <b>{{ $fmtPct($achNoa) }}</b> • Skor <b>{{ $sNoa }}</b></div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
              <div class="text-[11px] text-slate-500">RR</div>
              <div class="text-sm font-extrabold text-slate-900">{{ number_format($rr,2) }}%</div>
              <div class="text-[11px] text-slate-500">Ach: <b>{{ $fmtPct($achRr) }}</b> • Skor <b>{{ $sRr }}</b></div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
              <div class="text-[11px] text-slate-500">Komunitas</div>
              <div class="text-sm font-extrabold text-slate-900">{{ number_format($headlineAct,2) }}</div>
              <div class="text-[11px] text-slate-500">Ach: <b>{{ $fmtPct($achAct) }}</b> • Skor <b>{{ $sAct }}</b></div>
            </div>
          </div>
        </div>

        <div class="text-right shrink-0">
          <div class="text-xs text-slate-500">Total PI</div>
          <div class="text-2xl font-extrabold text-slate-900">
            {{ number_format($piTot, 2) }}
          </div>
          <div class="text-xs text-slate-500 mt-1">
            (OS {{ (int)round(($weights['os'] ?? 0)*100) }}% • NOA {{ (int)round(($weights['noa'] ?? 0)*100) }}% • RR {{ (int)round(($weights['rr'] ?? 0)*100) }}% • Kom {{ (int)round(($weights['activity'] ?? 0)*100) }}%)
          </div>
        </div>
      </div>

      {{-- TABLE --}}
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
            {{-- OS --}}
            <tr>
              <td class="px-3 py-2 font-semibold">
                Target OS Realisasi
                <div class="text-xs text-slate-500 font-normal mt-0.5">
                  OS dihitung sebagai realisasi (akumulasi). Jika ada penyesuaian, yang dipakai adalah OS setelah adjustment.
                </div>
              </td>

              <td class="px-3 py-2 text-right">
                {{ $fmtRp($it->target_os_disbursement ?? 0) }}
              </td>

              <td class="px-3 py-2 text-right">
                {{ $fmtRp($it->os_disbursement ?? 0) }}

                @if($hasOsMeta)
                  <div class="text-xs text-slate-500 mt-1 leading-relaxed">
                    <div>Total realisasi (raw): <b>{{ $fmtRp($osRaw ?? 0) }}</b></div>
                    <div>Penyesuaian: <b class="{{ $osAdjVal > 0 ? 'text-amber-700' : 'text-slate-700' }}">{{ $fmtRp($osAdjVal) }}</b></div>
                    <div class="text-slate-400 italic">
                      (yang dihitung untuk KPI: {{ $fmtRp($it->os_disbursement ?? 0) }})
                    </div>
                  </div>
                @endif
              </td>

              <td class="px-3 py-2 text-right">{{ $fmtPct($it->ach_os ?? 0) }}</td>
              <td class="px-3 py-2 text-center font-extrabold">{{ (int)($it->score_os ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['os'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($it->pi_os ?? 0),2) }}</td>
            </tr>

            {{-- NOA --}}
            <tr>
              <td class="px-3 py-2 font-semibold">
                Target NOA
                <div class="text-xs text-slate-500 font-normal mt-0.5">
                  NOA dihitung sebagai jumlah realisasi (akumulasi) dibanding target akumulasi.
                </div>
              </td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->target_noa_disbursement ?? 0),2) }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->noa_disbursement ?? 0),2) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($it->ach_noa ?? 0) }}</td>
              <td class="px-3 py-2 text-center font-extrabold">{{ (int)($it->score_noa ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['noa'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($it->pi_noa ?? 0),2) }}</td>
            </tr>

            {{-- RR --}}
            <tr>
              <td class="px-3 py-2 font-semibold">
                Repayment Rate
                <div class="text-xs text-slate-500 font-normal mt-0.5">
                  RR dibanding target RR. Skor mengikuti rubrik SO (khusus).
                </div>
              </td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->target_rr ?? 100),0) }}%</td>
              <td class="px-3 py-2 text-right">
                {{ number_format((float)($it->rr_pct ?? 0),2) }}%
                <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] {{ $rrB['cls'] }}">
                  {{ $rrB['label'] }}
                </span>
              </td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($it->ach_rr ?? 0) }}</td>
              <td class="px-3 py-2 text-center font-extrabold">{{ (int)($it->score_rr ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['rr'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($it->pi_rr ?? 0),2) }}</td>
            </tr>

            {{-- Activity / Komunitas --}}
            <tr>
              <td class="px-3 py-2 font-semibold">
                Handling Komunitas
                <div class="text-xs text-slate-500 font-normal mt-0.5">
                  Activity dihitung sebagai jumlah komunitas yang di-handle (akumulasi).
                </div>
              </td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->target_activity ?? 0),2) }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((float)($it->activity_actual ?? 0),2) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($it->ach_activity ?? 0) }}</td>
              <td class="px-3 py-2 text-center font-extrabold">{{ (int)($it->score_activity ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round(($weights['activity'] ?? 0)*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($it->pi_activity ?? 0),2) }}</td>
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