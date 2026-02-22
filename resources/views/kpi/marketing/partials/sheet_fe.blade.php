@php
  // ==========================================================
  // GLOBAL HELPERS (1x)
  // ==========================================================
  $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $d=2) => number_format((float)($n ?? 0), $d) . '%';

  $chipCls = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200';

  // Badge normal: makin besar makin bagus (achievement %)
  $badge = function ($ach) {
      $ach = (float)($ach ?? 0);
      if ($ach >= 100) return 'bg-emerald-100 text-emerald-800 border-emerald-200';
      if ($ach >= 80)  return 'bg-amber-100 text-amber-800 border-amber-200';
      return 'bg-rose-100 text-rose-800 border-rose-200';
  };

  // Badge reverse: makin kecil makin bagus (actual <= target bagus)
  $badgeReverse = function ($actual, $target) {
      $a = (float)($actual ?? 0);
      $t = (float)($target ?? 0);

      if ($t <= 0) {
          return ($a <= 0)
              ? 'bg-emerald-100 text-emerald-800 border-emerald-200'
              : 'bg-rose-100 text-rose-800 border-rose-200';
      }

      if ($a <= $t)       return 'bg-emerald-100 text-emerald-800 border-emerald-200';
      if ($a <= $t * 1.2) return 'bg-amber-100 text-amber-800 border-amber-200';
      return 'bg-rose-100 text-rose-800 border-rose-200';
  };

  // label akumulasi (opsional dari controller/service)
  $accText = null;
  if (!empty($startYtd) && !empty($endYtd)) {
      $accText = 'Akumulasi ' . \Carbon\Carbon::parse($startYtd)->format('d M Y') . ' – ' . \Carbon\Carbon::parse($endYtd)->format('d M Y');
  }

  // mode chip
  $modeLabel = strtoupper($mode ?? '');
  $modeChip = match (strtolower($mode ?? '')) {
      'realtime' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
      'eom'      => 'bg-indigo-100 text-indigo-800 border-indigo-200',
      default    => 'bg-slate-100 text-slate-700 border-slate-200',
  };

  // weights mapping (service: os_turun/migrasi/penalty)
  $wOs  = (float)($weights['os_turun'] ?? $weights['nett_os_down'] ?? 0);
  $wMg  = (float)($weights['migrasi']  ?? $weights['npl_migration'] ?? 0);
  $wPen = (float)($weights['penalty']  ?? 0);

  // helper text reverse
  $vsText = function ($actual, $target) use ($fmtPct) {
      return $fmtPct($actual) . ' / ' . $fmtPct($target);
  };

  // Reverse status detail: tampilkan gap dan ratio
  $reverseMeta = function(float $actual, float $target): array {
      $a = max(0.0, (float)$actual);
      $t = max(0.0, (float)$target);

      // gap (berapa persen point di atas target)
      $gap = $a - $t;

      // ratio (berapa kali lipat)
      $ratio = ($t > 0) ? ($a / $t) : null;

      // severity label
      $severity = 'OK';
      if ($t <= 0) {
          $severity = ($a <= 0) ? 'OK' : 'ALERT';
      } else {
          if ($a <= $t) $severity = 'OK';
          elseif ($a <= $t * 1.2) $severity = 'WARN';
          else $severity = 'ALERT';
      }

      return [
          'a' => $a,
          't' => $t,
          'gap' => $gap,
          'ratio' => $ratio,
          'severity' => $severity,
      ];
  };

  $reverseLabel = function(array $m) use ($fmtPct) {
      // contoh: "ALERT • +37.09pp • 124.6x"
      $pp = $fmtPct($m['gap'], 2); // gap in percentage points
      $x  = is_null($m['ratio']) ? null : number_format($m['ratio'], 1) . 'x';

      if ($m['severity'] === 'OK') {
          return "OK • {$fmtPct($m['a'],2)} ≤ {$fmtPct($m['t'],2)}";
      }

      if ($m['severity'] === 'WARN') {
          return "WARN • +{$pp}pp" . ($x ? " • {$x}" : "");
      }

      return "ALERT • +{$pp}pp" . ($x ? " • {$x}" : "");
  };

@endphp

<div class="space-y-6">

  {{-- ==========================================================
      TLFE / LEADER RECAP
     ========================================================== --}}
  @if(!empty($tlRecap))
    @php
      $lr = strtoupper($tlRecap->leader_role ?? 'TLFE');

      $tlTargetMg = (float)($tlRecap->target_npl_migration_pct ?? 0);
      $tlActualMg = (float)($tlRecap->npl_migration_pct ?? 0);
      $tlMgClass  = $badgeReverse($tlActualMg, $tlTargetMg);

      $baselineOk = true; // recap sifatnya agregat; kalau mau, bisa dari MIN baseline_ok di service
    @endphp

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">

      {{-- HEADER --}}
      <div class="px-5 py-4 border-b border-slate-200 flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <div class="text-lg font-extrabold text-slate-900 uppercase">
              KPI {{ $lr }} – {{ $tlRecap->name ?? '-' }}
            </div>

            @if($accText)
              <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border bg-indigo-50 text-indigo-700 border-indigo-200">
                {{ $accText }}
              </span>
            @endif

            @if(!empty($modeLabel))
              <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border {{ $modeChip }}">
                MODE {{ $modeLabel }}
              </span>
            @endif

            @if(!empty($tlRecap->scope_count))
              <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200">
                Scope: {{ (int)$tlRecap->scope_count }} FE
              </span>
            @endif
          </div>

          <div class="text-xs text-slate-500 mt-2 flex flex-wrap gap-2">
            <span class="{{ $chipCls }}">Nett OS Kol 2: nilai Rupiah turun murni</span>
            <span class="{{ $chipCls }}">Migrasi NPL: reverse (lebih kecil lebih baik)</span>
            <span class="{{ $chipCls }}">Denda: loan_installments.penalty_paid</span>
            <span class="{{ $chipCls }}">Nett/OS awal: hanya info</span>
          </div>
        </div>

        <div class="text-right shrink-0">
          <div class="text-xs text-slate-500">TOTAL PI {{ $lr }}</div>
          <div class="text-2xl font-extrabold text-slate-900">
            {{ number_format((float)($tlRecap->pi_total ?? 0), 2) }}
          </div>
          <div class="text-xs text-slate-500 mt-1">
            (OS {{ (int)round($wOs*100) }}% • MG {{ (int)round($wMg*100) }}% • Denda {{ (int)round($wPen*100) }}%)
          </div>
        </div>
        @if(!$baselineOk)
          <div class="px-5 py-3 bg-rose-50 border-b border-rose-200 text-rose-800 text-sm">
            <b>Baseline Check:</b> ada kondisi data/baseline yang belum lolos. 
            PI tetap ditampilkan, tapi mohon verifikasi sumber data & rule baseline sebelum dijadikan evaluasi.
          </div>
        @endif
      </div>

      {{-- MINI CARDS (mobile-friendly) --}}
      <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Nett OS Down</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($tlRecap->nett_os_down ?? 0) }}</div>
          <div class="text-xs text-slate-500 mt-1">
            Ach {{ $fmtPct($tlRecap->ach_nett_os_down ?? 0) }} • Skor {{ number_format((float)($tlRecap->score_nett_os_down ?? 0),2) }}
          </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Migrasi NPL</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtPct($tlActualMg, 2) }}</div>
          <div class="text-xs text-slate-500 mt-1">
            Target {{ $fmtPct($tlTargetMg, 2) }} • Skor {{ number_format((float)($tlRecap->score_npl_migration ?? 0),2) }}
          </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Denda Masuk</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($tlRecap->penalty_actual ?? 0) }}</div>
          <div class="text-xs text-slate-500 mt-1">
            Ach {{ $fmtPct($tlRecap->ach_penalty ?? 0) }} • Skor {{ number_format((float)($tlRecap->score_penalty ?? 0),2) }}
          </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Komposisi Turun</div>
          <div class="text-sm font-bold text-slate-900">
            Murni: {{ $fmtRp($it->nett_os_down ?? 0) }}
          </div>
          <div class="text-sm font-bold text-slate-900 mt-1">
            Migrasi: {{ $fmtRp($it->nett_os_down_migrasi ?? 0) }}
          </div>
          <div class="text-xs text-slate-500 mt-1">
            Total turun: {{ $fmtRp($it->nett_os_down_total ?? 0) }} • Nett/OS awal: {{ $fmtPct($infoPct, 2) }}
          </div>
        </div>
      </div>

      {{-- TABLE --}}
      <div class="px-4 pb-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-900 text-white">
            <tr>
              <th class="text-left px-3 py-2">KPI</th>
              <th class="text-right px-3 py-2">Target</th>
              <th class="text-right px-3 py-2">Actual</th>
              <th class="text-right px-3 py-2">Status</th>
              <th class="text-center px-3 py-2">Skor</th>
              <th class="text-right px-3 py-2">Bobot</th>
              <th class="text-right px-3 py-2">PI</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-200">
            {{-- OS --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Nett Penurunan OS Kol 2 (Scope)</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tlRecap->target_nett_os_down ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                {{ $fmtRp($tlRecap->nett_os_down ?? 0) }}
                <div class="text-xs text-slate-500 mt-1 space-y-0.5">
                  <div>Info Nett/OS awal: <b>{{ $fmtPct($infoPct, 2) }}</b></div>
                  <div>Total turun: <b>{{ $fmtRp($it->nett_os_down_total ?? 0) }}</b></div>
                  <div>Turun karena migrasi: <b>{{ $fmtRp($it->nett_os_down_migrasi ?? 0) }}</b></div>
                </div>
              </td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($tlRecap->ach_nett_os_down ?? 0) }}">
                  Ach {{ $fmtPct($tlRecap->ach_nett_os_down ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_nett_os_down ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wOs*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($tlRecap->pi_nett_os_down ?? 0), 2) }}</td>
            </tr>

            {{-- MIGRASI (reverse) --}}
            <tr>
              <td class="px-3 py-2 font-semibold">
                Pemburukan / Migrasi NPL <span class="text-xs text-slate-500">(Reverse)</span>
                <div class="text-xs text-slate-500 mt-1">Lebih kecil lebih baik. Status ditampilkan: <b>Actual/Target</b>.</div>
              </td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($tlTargetMg, 2) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($tlActualMg, 2) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $tlMgClass }}">
                  {{ $vsText($tlActualMg, $tlTargetMg) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_npl_migration ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wMg*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($tlRecap->pi_npl_migration ?? 0), 2) }}</td>
            </tr>

            {{-- PENALTY --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Target Denda Masuk (Scope)</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tlRecap->target_penalty ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tlRecap->penalty_actual ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($tlRecap->ach_penalty ?? 0) }}">
                  Ach {{ $fmtPct($tlRecap->ach_penalty ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($tlRecap->score_penalty ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wPen*100) }}%</td>
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

      {{-- RANKING --}}
      @if(!empty($tlRecap->rankings) && count($tlRecap->rankings) > 0)
        <div class="px-5 pb-5">
          <div class="flex items-center justify-between mb-3">
            <div class="text-sm font-extrabold text-slate-900 uppercase">Ranking FE (Scope)</div>
            <div class="text-xs text-slate-500">Urut berdasarkan <b>Total PI</b></div>
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
              </tbody>
            </table>
          </div>

          <div class="mt-2 text-xs text-slate-500">
            Catatan: Top 3 diberi highlight. Breakdown PI membantu TLFE melihat sumber skor (OS/Migrasi/Denda).
          </div>
        </div>
      @endif
    </div>
  @endif

  {{-- ==========================================================
      FE DETAIL (per FE)
     ========================================================== --}}
  @forelse(($items ?? []) as $it)
    @php
      $piTotal = (float)($it->pi_total ?? 0);

      $infoPct = (float)($it->nett_os_down_pct_info ?? $it->os_kol2_turun_pct ?? 0);

      $tMg = (float)($it->target_npl_migration_pct ?? 0);
      $aMg = (float)($it->npl_migration_pct ?? 0);
      $mgClass = $badgeReverse($aMg, $tMg);

      $baselineOk = ((int)($it->baseline_ok ?? 1) === 1);
    @endphp

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200 flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        <div>
          <div class="flex flex-wrap items-center gap-2">
            <div class="text-xl font-extrabold text-slate-900 uppercase">{{ $it->name ?? '-' }}</div>

            @if($accText)
              <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border bg-indigo-50 text-indigo-700 border-indigo-200">
                {{ $accText }}
              </span>
            @endif

            @if(!empty($modeLabel))
              <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border {{ $modeChip }}">
                MODE {{ $modeLabel }}
              </span>
            @endif

            @if($baselineOk)
              <span class="inline-flex rounded-full bg-emerald-100 text-emerald-800 px-3 py-1 text-xs font-semibold border border-emerald-200">
                BASELINE OK
              </span>
            @else
              <span class="inline-flex rounded-full bg-rose-100 text-rose-800 px-3 py-1 text-xs font-semibold border border-rose-200">
                BASELINE CHECK
              </span>
            @endif
          </div>

          <div class="text-sm text-slate-600 mt-1">
            FE • Code: <b>{{ $it->ao_code ?? '-' }}</b>
          </div>
        </div>

        <div class="text-right">
          <div class="text-xs text-slate-500">Total PI</div>
          <div class="text-2xl font-extrabold text-slate-900">{{ number_format($piTotal, 2) }}</div>
          <div class="text-xs text-slate-500 mt-1">
            (OS {{ (int)round($wOs*100) }}% • MG {{ (int)round($wMg*100) }}% • Denda {{ (int)round($wPen*100) }}%)
          </div>
        </div>
      </div>

      {{-- MINI CARDS --}}
      <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Nett OS Down</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($it->nett_os_down ?? 0) }}</div>
          <div class="text-xs text-slate-500 mt-1">Ach {{ $fmtPct($it->ach_nett_os_down ?? 0) }} • Skor {{ number_format((float)($it->score_nett_os_down ?? 0),2) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Migrasi NPL</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtPct($aMg, 2) }}</div>
          <div class="text-xs text-slate-500 mt-1">Target {{ $fmtPct($tMg, 4) }} • Skor {{ number_format((float)($it->score_npl_migration ?? 0),2) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Denda Masuk</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($it->penalty_actual ?? 0) }}</div>
          <div class="text-xs text-slate-500 mt-1">Ach {{ $fmtPct($it->ach_penalty ?? 0) }} • Skor {{ number_format((float)($it->score_penalty ?? 0),2) }}</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Info Nett/OS Awal</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtPct($infoPct, 2) }}</div>
          <div class="text-xs text-slate-500 mt-1">Total turun {{ $fmtRp($it->nett_os_down_total ?? 0) }}</div>
        </div>
      </div>

      {{-- TABLE --}}
      <div class="px-4 pb-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-orange-500 text-white">
            <tr>
              <th class="text-left px-3 py-2">KPI</th>
              <th class="text-right px-3 py-2">Target</th>
              <th class="text-right px-3 py-2">Actual</th>
              <th class="text-right px-3 py-2">Status</th>
              <th class="text-center px-3 py-2">Skor</th>
              <th class="text-right px-3 py-2">Bobot</th>
              <th class="text-right px-3 py-2">PI</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-200">
            {{-- OS --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Nett Penurunan OS Kol 2</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($it->target_nett_os_down ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                {{ $fmtRp($it->nett_os_down ?? 0) }}
                <div class="text-xs text-slate-500 mt-1">
                  Info Nett/OS awal: {{ $fmtPct($infoPct, 2) }}
                  <span class="whitespace-nowrap"> • Total turun: {{ $fmtRp($it->nett_os_down_total ?? 0) }}</span>
                  <span class="whitespace-nowrap"> • Migrasi: {{ $fmtRp($it->nett_os_down_migrasi ?? 0) }}</span>
                </div>
              </td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($it->ach_nett_os_down ?? 0) }}">
                  Ach {{ $fmtPct($it->ach_nett_os_down ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->score_nett_os_down ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wOs*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_nett_os_down ?? 0), 2) }}</td>
            </tr>

            {{-- MIGRASI --}}
            <tr>
              <td class="px-3 py-2 font-semibold">
                Pemburukan / Migrasi NPL <span class="text-xs text-slate-500">(Reverse)</span>
                <div class="text-xs text-slate-500 mt-1">Lebih kecil lebih baik. Status: <b>Actual/Target</b>.</div>
              </td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($tMg, 2) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($aMg, 2) }}</td>
              <td class="px-3 py-2 text-right">
                @php
                  $mgMeta = $reverseMeta($aMg, $tMg);
                @endphp

                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $mgClass }}">
                  {{ $reverseLabel($mgMeta) }}
                </span>

                <div class="text-[11px] text-slate-500 mt-1">
                  Actual: <b>{{ $fmtPct($aMg, 2) }}</b> • Target: <b>≤ {{ $fmtPct($tMg, 2) }}</b>
                </div>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->score_npl_migration ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wMg*100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_npl_migration ?? 0), 2) }}</td>
            </tr>

            {{-- PENALTY --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Target Denda Masuk</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($it->target_penalty ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($it->penalty_actual ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($it->ach_penalty ?? 0) }}">
                  Ach {{ $fmtPct($it->ach_penalty ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($it->score_penalty ?? 0), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wPen*100) }}%</td>
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