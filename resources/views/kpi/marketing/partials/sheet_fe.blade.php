@php
  $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n) => number_format((float)($n ?? 0), 2) . '%';

  // Badge normal: makin besar makin bagus (untuk achievement % yg non-reverse)
  $badge = function($ach){
      $ach = (float)($ach ?? 0);
      if ($ach >= 100) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
      if ($ach >= 80)  return 'bg-amber-50 text-amber-700 border-amber-200';
      return 'bg-rose-50 text-rose-700 border-rose-200';
  };

  // Badge reverse: makin kecil makin bagus (untuk migrasi NPL; pakai ACTUAL vs TARGET)
  $badgeReverse = function($actual, $target){
      $a = (float)($actual ?? 0);
      $t = (float)($target ?? 0);
      if ($t <= 0) {
          // fallback: kalau target 0, treat 0 sebagai bagus
          if ($a <= 0) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
          return 'bg-rose-50 text-rose-700 border-rose-200';
      }
      if ($a <= $t)        return 'bg-emerald-50 text-emerald-700 border-emerald-200';
      if ($a <= ($t * 2))  return 'bg-amber-50 text-amber-700 border-amber-200';
      return 'bg-rose-50 text-rose-700 border-rose-200';
  };

  // Helper tampilan: Actual vs Target (reverse) biar gak membingungkan
  $fmtVs = function($actual, $target) use ($fmtPct){
      return $fmtPct($actual) . ' <span class="text-slate-400">/</span> ' . $fmtPct($target);
  };

  // weights mapping (service: os_turun/migrasi/penalty)
  $wOs  = (float)($weights['os_turun'] ?? $weights['nett_os_down'] ?? 0);
  $wMg  = (float)($weights['migrasi']  ?? $weights['npl_migration'] ?? 0);
  $wPen = (float)($weights['penalty']  ?? 0);
@endphp

{{-- =========================
    FE SHEET (cards)
   ========================= --}}

<div class="space-y-6">

  {{-- TLFE Recap (kalau ada) --}}
  @if(!empty($tlRecap))
    @php
      $lr = strtoupper($tlRecap->leader_role ?? 'TLFE');

      $tlTargetMg = (float)($tlRecap->target_npl_migration_pct ?? 0);
      $tlActualMg = (float)($tlRecap->npl_migration_pct ?? 0);
      $tlMgClass  = $badgeReverse($tlActualMg, $tlTargetMg);
    @endphp

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-4">
        <div class="min-w-0">
          <div class="text-lg font-extrabold text-slate-900 uppercase">
            KPI {{ $lr }} – {{ $tlRecap->name ?? '-' }}
          </div>
          <div class="text-sm text-slate-600 mt-1">
            Scope FE: <b>{{ (int)($tlRecap->scope_count ?? 0) }}</b>
          </div>

          {{-- Catatan yang lebih “jelas” --}}
          <div class="mt-2 text-xs text-slate-600 leading-relaxed">
            <div class="flex flex-wrap gap-x-2 gap-y-1">
              <span class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-2 py-1">
                <b>Nett OS Kol 2</b>: dinilai <b>Rupiah nett turun murni</b>
              </span>
              <span class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-2 py-1">
                <b>Nett/OS awal</b>: hanya <b>info</b>
              </span>
              <span class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-2 py-1">
                <b>Migrasi NPL</b>: <b>reverse</b> (lebih kecil lebih baik)
              </span>
              <span class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 px-2 py-1">
                <b>Denda</b>: dari <b>loan_installments.penalty_paid</b>
              </span>
            </div>
          </div>
        </div>

        <div class="text-right shrink-0">
          <div class="text-xs text-slate-500">TOTAL PI {{ $lr }}</div>
          <div class="text-2xl font-extrabold text-slate-900">
            {{ number_format((float)($tlRecap->pi_total ?? 0), 2) }}
          </div>
        </div>
      </div>

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
              <td class="px-3 py-2 font-semibold">Nett Penurunan OS Kol 2 (Scope)</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tlRecap->target_nett_os_down ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                {{ $fmtRp($tlRecap->nett_os_down ?? 0) }}
                <div class="text-xs text-slate-500 mt-1">
                  Info: Nett/OS awal = {{ $fmtPct($tlRecap->nett_os_down_pct_info ?? 0) }}
                  @if(($tlRecap->nett_os_down_total ?? 0) || ($tlRecap->nett_os_down_migrasi ?? 0))
                    <span class="whitespace-nowrap"> • Total turun: {{ $fmtRp($tlRecap->nett_os_down_total ?? 0) }}</span>
                    <span class="whitespace-nowrap"> • Migrasi: {{ $fmtRp($tlRecap->nett_os_down_migrasi ?? 0) }}</span>
                  @endif
                </div>
              </td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-1 rounded-full border text-xs font-semibold {{ $badge($tlRecap->ach_nett_os_down ?? 0) }}">
                  {{ $fmtPct($tlRecap->ach_nett_os_down ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_nett_os_down ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wOs * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($tlRecap->pi_nett_os_down ?? 0), 2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">
                Pemburukan / Migrasi NPL <span class="text-xs text-slate-500">(Reverse, Scope)</span>
                <div class="text-xs text-slate-500 mt-1">Lebih kecil lebih baik. Ditampilkan: <b>Actual / Target</b>.</div>
              </td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($tlTargetMg) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($tlActualMg) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-1 rounded-full border text-xs font-semibold {!! $tlMgClass !!}">
                  {!! $fmtVs($tlActualMg, $tlTargetMg) !!}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_npl_migration ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wMg * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($tlRecap->pi_npl_migration ?? 0), 2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">Target Denda Masuk (Scope)</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tlRecap->target_penalty ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tlRecap->penalty_actual ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-1 rounded-full border text-xs font-semibold {{ $badge($tlRecap->ach_penalty ?? 0) }}">
                  {{ $fmtPct($tlRecap->ach_penalty ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_penalty ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wPen * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($tlRecap->pi_penalty ?? 0), 2) }}</td>
            </tr>
          </tbody>

          <tfoot>
            <tr class="bg-yellow-200">
              <td colspan="6" class="px-3 py-2 font-extrabold text-right">TOTAL PI {{ $lr }}</td>
              <td class="px-3 py-2 font-extrabold text-right">{{ number_format((float)($tlRecap->pi_total ?? 0), 2) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    
      @if(!empty($tlRecap->rankings) && count($tlRecap->rankings) > 0)
        <div class="px-5 pb-5">
            <div class="flex items-center justify-between mb-3">
            <div class="text-sm font-extrabold text-slate-900 uppercase">
                Ranking FE (Scope)
            </div>
            <div class="text-xs text-slate-500">
                Urut berdasarkan <b>Total PI</b> (desc)
            </div>
            </div>

            <div class="overflow-x-auto rounded-xl border border-slate-200">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50">
                <tr>
                    <th class="text-left px-3 py-2 w-14">#</th>
                    <th class="text-left px-3 py-2">FE</th>
                    <th class="text-left px-3 py-2">Code</th>

                    <th class="text-right px-3 py-2">PI OS</th>
                    <th class="text-right px-3 py-2">PI Migrasi</th>
                    <th class="text-right px-3 py-2">PI Denda</th>

                    <th class="text-right px-3 py-2 font-extrabold">Total PI</th>
                </tr>
                </thead>

                <tbody class="divide-y divide-slate-200 bg-white">
                @foreach($tlRecap->rankings as $r)
                    @php
                    $isTop = ((int)$r->rank <= 3);
                    $rowClass = $isTop ? 'bg-emerald-50/40' : '';
                    @endphp

                    <tr class="{{ $rowClass }}">
                    <td class="px-3 py-2 font-bold text-slate-900">
                        {{ $r->rank }}
                    </td>

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

                    <td class="px-3 py-2 text-right font-extrabold text-slate-900">
                        {{ number_format((float)($r->pi_total ?? 0), 2) }}
                    </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>

            <div class="mt-2 text-xs text-slate-500">
            Catatan: Top 3 diberi highlight. Breakdown PI membantu tahu sumber skor (OS/Migrasi/Denda).
            </div>
        </div>
        @endif

    </div>
  @endif

  {{-- FE cards --}}
  @forelse(($items ?? []) as $it)
    @php
      $piTotal = (float)($it->pi_total ?? $it->score_total ?? 0);
      $infoPct = (float)($it->nett_os_down_pct_info ?? $it->os_kol2_turun_pct ?? 0);

      $tMg = (float)($it->target_npl_migration_pct ?? 0);
      $aMg = (float)($it->npl_migration_pct ?? 0);
      $mgClass = $badgeReverse($aMg, $tMg);
    @endphp

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-4">
        <div>
          <div class="text-xl font-extrabold text-slate-900 uppercase">{{ $it->name ?? '-' }}</div>
          <div class="text-sm text-slate-600 mt-1">
            FE • Code: <b>{{ $it->ao_code ?? '-' }}</b>
          </div>
        </div>

        <div class="text-right">
          <div class="text-xs text-slate-500">Total PI</div>
          <div class="text-2xl font-extrabold text-slate-900">{{ number_format($piTotal, 2) }}</div>
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
              <td class="px-3 py-2 font-semibold">Nett Penurunan OS Kol 2</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($it->target_nett_os_down ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                {{ $fmtRp($it->nett_os_down ?? $it->os_kol2_turun_murni ?? 0) }}
                <div class="text-xs text-slate-500 mt-1">
                  Info: Nett/OS awal = {{ $fmtPct($infoPct) }}
                  <span class="whitespace-nowrap"> • Total turun: {{ $fmtRp($it->nett_os_down_total ?? $it->os_kol2_turun_total ?? 0) }}</span>
                  <span class="whitespace-nowrap"> • Migrasi: {{ $fmtRp($it->nett_os_down_migrasi ?? $it->os_kol2_turun_migrasi ?? 0) }}</span>
                </div>
              </td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-1 rounded-full border text-xs font-semibold {{ $badge($it->ach_nett_os_down ?? 0) }}">
                  {{ $fmtPct($it->ach_nett_os_down ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->score_nett_os_down ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wOs * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_nett_os_down ?? 0), 2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">
                Pemburukan / Migrasi NPL <span class="text-xs text-slate-500">(Reverse)</span>
                <div class="text-xs text-slate-500 mt-1">Lebih kecil lebih baik. Ditampilkan: <b>Actual / Target</b>.</div>
              </td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($tMg) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($aMg) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-1 rounded-full border text-xs font-semibold {!! $mgClass !!}">
                  {!! $fmtVs($aMg, $tMg) !!}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->score_npl_migration ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wMg * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_npl_migration ?? 0), 2) }}</td>
            </tr>

            <tr>
              <td class="px-3 py-2 font-semibold">Target Denda Masuk</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($it->target_penalty ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($it->penalty_actual ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-1 rounded-full border text-xs font-semibold {{ $badge($it->ach_penalty ?? 0) }}">
                  {{ $fmtPct($it->ach_penalty ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->score_penalty ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wPen * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_penalty ?? 0), 2) }}</td>
            </tr>
          </tbody>

          <tfoot>
            <tr class="bg-yellow-200">
              <td colspan="6" class="px-3 py-2 font-extrabold text-right">TOTAL</td>
              <td class="px-3 py-2 font-extrabold text-right">{{ number_format($piTotal, 2) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  @empty
    <div class="rounded-2xl border border-slate-200 bg-white p-6 text-slate-600">
      Data KPI FE belum tersedia untuk periode ini.
    </div>
  @endforelse

</div>
