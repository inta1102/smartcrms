@php
  $summary = $visitCoverageSummary ?? [];
  $perf    = $visitCoveragePerformance ?? [];
  $rows    = $visitCoverageRows ?? collect();

  if (is_array($rows)) {
      $rows = collect($rows);
  }

  // summary coverage
  $totalNoa     = (int)($summary['total_noa'] ?? 0);
  $doneVisit    = (int)($summary['done_visit'] ?? 0);
  $planOnly     = (int)($summary['plan_only'] ?? 0);
  $belumVisit   = (int)($summary['belum_visit'] ?? 0);
  $coveragePct  = (float)($summary['coverage_pct'] ?? 0);
  $osTotal      = (float)($summary['os_total'] ?? 0);
  $osBelumVisit = (float)($summary['os_belum_visit'] ?? 0);
  $ltNoa        = (int)($summary['lt_noa'] ?? 0);
  $dpkNoa       = (int)($summary['dpk_noa'] ?? 0);

  // performance
  $visitToday          = (int)($perf['visit_today'] ?? 0);
  $visitWeek           = (int)($perf['visit_week'] ?? 0);
  $visitMtd            = (int)($perf['visit_mtd'] ?? 0);
  $visitYtd            = (int)($perf['visit_ytd'] ?? 0);
  $avgVisitPerRo       = (float)($perf['avg_visit_per_ro'] ?? 0);
  $planToday           = (int)($perf['plan_today'] ?? 0);
  $planRealizationPct  = (float)($perf['plan_realization_pct'] ?? 0);

  $badgeCoverage = $coveragePct >= 70
      ? 'text-emerald-700'
      : ($coveragePct >= 40 ? 'text-amber-700' : 'text-rose-700');

  $badgePlanReal = $planRealizationPct >= 90
      ? 'text-emerald-700'
      : ($planRealizationPct >= 70 ? 'text-amber-700' : 'text-rose-700');
@endphp

<div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
  <div class="p-4 border-b border-slate-200">
    <div class="font-extrabold text-slate-900">Coverage Kunjungan per RO</div>
    <div class="text-xs text-slate-500 mt-1">
      Ringkasan coverage visit berdasarkan seluruh portfolio kelolaan RO pada periode terpilih.
    </div>
    <div class="text-xs text-slate-500 mt-1">
      Coverage visit dibaca s/d <b>{{ $coverageTo ?? '-' }}</b>,
      portfolio berdasarkan snapshot <b>{{ $latestPosDate ?? '-' }}</b>.
    </div>
  </div>

  {{-- =======================
       A. COVERAGE SUMMARY
     ======================= --}}
  <div class="p-4 border-b border-slate-200">
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3">
      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Total NOA Scope TL</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-1">
          {{ number_format($totalNoa, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Sudah Visit DONE</div>
        <div class="text-2xl font-extrabold text-emerald-700 mt-1">
          {{ number_format($doneVisit, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Masih Plan</div>
        <div class="text-2xl font-extrabold text-sky-700 mt-1">
          {{ number_format($planOnly, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Belum Divisit</div>
        <div class="text-2xl font-extrabold text-rose-700 mt-1">
          {{ number_format($belumVisit, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Coverage Scope TL</div>
        <div class="text-2xl font-extrabold mt-1 {{ $badgeCoverage }}">
          {{ number_format($coveragePct, 2, ',', '.') }}%
        </div>
      </div>
    </div>
  </div>

  {{-- =======================
       B. VISIT PERFORMANCE
     ======================= --}}
  <div class="p-4 border-b border-slate-200 bg-slate-50/40">
    <div class="mb-3">
      <div class="text-sm font-bold text-slate-900">Visit Performance</div>
      <div class="text-xs text-slate-500">
        Aktivitas kunjungan tim berdasarkan akun distinct yang sudah tersentuh.
      </div>
    </div>

    <!-- <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-7 gap-3">
      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Visit Today</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-1">
          {{ number_format($visitToday, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Visit This Week</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-1">
          {{ number_format($visitWeek, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Visit MTD</div>
        <div class="text-2xl font-extrabold text-sky-700 mt-1">
          {{ number_format($visitMtd, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Visit YTD</div>
        <div class="text-2xl font-extrabold text-indigo-700 mt-1">
          {{ number_format($visitYtd, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Avg Visit / RO</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-1">
          {{ number_format($avgVisitPerRo, 2, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Plan Today</div>
        <div class="text-2xl font-extrabold text-sky-700 mt-1">
          {{ number_format($planToday, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">Plan Realization %</div>
        <div class="text-2xl font-extrabold mt-1 {{ $badgePlanReal }}">
          {{ number_format($planRealizationPct, 2, ',', '.') }}%
        </div>
      </div>
    </div> -->
  </div>

  {{-- =======================
       C. RISK / PORTFOLIO EXPOSURE
     ======================= --}}
  <!-- <div class="p-4 border-b border-slate-200">
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">LT Scope TL</div>
        <div class="text-2xl font-extrabold text-amber-700 mt-1">
          {{ number_format($ltNoa, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">DPK Scope TL</div>
        <div class="text-2xl font-extrabold text-rose-700 mt-1">
          {{ number_format($dpkNoa, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">OS Total Scope TL</div>
        <div class="text-2xl font-extrabold text-slate-900 mt-1">
          Rp {{ number_format($osTotal, 0, ',', '.') }}
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <div class="text-xs text-slate-500">OS Belum Visit</div>
        <div class="text-2xl font-extrabold text-rose-700 mt-1">
          Rp {{ number_format($osBelumVisit, 0, ',', '.') }}
        </div>
      </div>
    </div>
  </div> -->

  {{-- =======================
       D. TABLE PRODUCTIVITY
     ======================= --}}
  <div class="p-4 overflow-x-auto">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50">
        <tr class="text-slate-700">
          <th class="text-left px-3 py-2">RO</th>
          <th class="text-left px-3 py-2">AO</th>
          <th class="text-right px-3 py-2">Total NOA</th>
          <th class="text-right px-3 py-2">Visit DONE</th>
          <th class="text-right px-3 py-2">Visit Today</th>
          <th class="text-right px-3 py-2">Visit Week</th>
          <th class="text-right px-3 py-2">Visit MTD</th>
          <th class="text-right px-3 py-2">Visit YTD</th>
          <th class="text-right px-3 py-2">Plan Today</th>
          <th class="text-right px-3 py-2">Plan Only</th>
          <th class="text-right px-3 py-2">Belum Visit</th>
          <th class="text-right px-3 py-2">Coverage</th>
          <th class="text-right px-3 py-2">LT</th>
          <th class="text-right px-3 py-2">DPK</th>
          <th class="text-right px-3 py-2">OS Total</th>
          <th class="text-right px-3 py-2">OS Belum Visit</th>
        </tr>
      </thead>

      <tbody class="divide-y divide-slate-200">
        @forelse($rows as $r)
          <tr class="hover:bg-slate-50/60">
            <td class="px-3 py-2 font-semibold text-slate-900 whitespace-nowrap">
              {{ $r->ro_name ?? '-' }}
            </td>
            <td class="px-3 py-2 font-mono whitespace-nowrap">
              {{ $r->ao_code ?? '-' }}
            </td>
            <td class="px-3 py-2 text-right">
              {{ number_format((int)($r->total_noa ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right text-emerald-700 font-bold">
              {{ number_format((int)($r->done_visit ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right font-semibold text-slate-900">
              {{ number_format((int)($r->visit_today ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right font-semibold text-slate-900">
              {{ number_format((int)($r->visit_week ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right font-semibold text-sky-700">
              {{ number_format((int)($r->visit_mtd ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right font-semibold text-indigo-700">
              {{ number_format((int)($r->visit_ytd ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right text-sky-700 font-bold">
              {{ number_format((int)($r->plan_today ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right text-sky-700 font-bold">
              {{ number_format((int)($r->plan_only ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right text-rose-700 font-bold">
              {{ number_format((int)($r->belum_visit ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right font-semibold">
              {{ number_format((float)($r->coverage_pct ?? 0), 2, ',', '.') }}%
            </td>
            <td class="px-3 py-2 text-right">
              {{ number_format((int)($r->lt_noa ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right">
              {{ number_format((int)($r->dpk_noa ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right font-semibold whitespace-nowrap">
              Rp {{ number_format((float)($r->os_total ?? 0), 0, ',', '.') }}
            </td>
            <td class="px-3 py-2 text-right font-semibold text-rose-700 whitespace-nowrap">
              Rp {{ number_format((float)($r->os_belum_visit ?? 0), 0, ',', '.') }}
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="16" class="px-3 py-6 text-center text-slate-500">
              Belum ada data coverage kunjungan per RO pada periode ini.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>