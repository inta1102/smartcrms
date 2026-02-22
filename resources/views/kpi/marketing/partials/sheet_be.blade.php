@php
  // ==========================================================
  // GLOBAL HELPERS (1x)
  // ==========================================================
  $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $d=2) => number_format((float)($n ?? 0), $d) . '%';

  $chipCls = 'inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200';

  // Badge normal: makin besar makin bagus (achievement %)
  $badge = function($ach){
      $ach = (float)($ach ?? 0);
      if ($ach >= 100) return 'bg-emerald-100 text-emerald-800 border-emerald-200';
      if ($ach >= 80)  return 'bg-amber-100 text-amber-800 border-amber-200';
      return 'bg-rose-100 text-rose-800 border-rose-200';
  };

  // label akumulasi (dari controller/service BE)
  $accText = null;
  if (!empty($startYtd) && !empty($endYtd)) {
      $accText = 'Akumulasi ' . \Carbon\Carbon::parse($startYtd)->format('d M Y') . ' – ' . \Carbon\Carbon::parse($endYtd)->format('d M Y');
  }

  // mode (realtime/eom/monthly/mixed)
  $modeLabel = strtoupper(trim((string)($mode ?? '')));
  $modeChip = match (strtolower(trim((string)($mode ?? '')))) {
      'realtime' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
      'eom'      => 'bg-indigo-100 text-indigo-800 border-indigo-200',
      'monthly'  => 'bg-slate-100 text-slate-700 border-slate-200',
      'mixed'    => 'bg-violet-100 text-violet-800 border-violet-200',
      default    => 'bg-slate-100 text-slate-700 border-slate-200',
  };

  // Bobot BE (default kalau weights belum dikirim)
  $wOs    = (float)($weights['os']    ?? 0.50);
  $wNoa   = (float)($weights['noa']   ?? 0.10);
  $wBunga = (float)($weights['bunga'] ?? 0.20);
  $wDenda = (float)($weights['denda'] ?? 0.20);

  // normalize: kalau items masih array biasa
  $items = $items ?? [];
@endphp

<div class="space-y-6">

  @forelse($items as $it)
    @php
      // support object/array
      $isObj = is_object($it);

      $name = $isObj ? ($it->name ?? '-') : ($it['name'] ?? '-');
      $code = $isObj ? ($it->code ?? '') : ($it['code'] ?? '');

      $target = $isObj ? ($it->target ?? []) : ($it['target'] ?? []);
      $actual = $isObj ? ($it->actual ?? []) : ($it['actual'] ?? []);
      $score  = $isObj ? ($it->score ?? [])  : ($it['score'] ?? []);
      $pi     = $isObj ? ($it->pi ?? [])     : ($it['pi'] ?? []);

      $piTotal = (float)($pi['total'] ?? 0);

      $src    = $isObj ? ($it->source ?? 'realtime') : ($it['source'] ?? 'realtime');
      $status = $isObj ? ($it->status ?? null)       : ($it['status'] ?? null);

      $baselineOk = (int)($isObj ? ($it->baseline_ok ?? 1) : ($it['baseline_ok'] ?? 1)) === 1;

      // ✅ NORMALIZE utk match aman
      $nameUp = strtoupper(trim((string)$name));
      $code13 = trim((string)$code);

      // values
      $tOs = (float)($target['os'] ?? 0);
      $aOs = (float)($actual['os'] ?? 0);

      $tNoa = (int)($target['noa'] ?? 0);
      $aNoa = (int)($actual['noa'] ?? 0);

      $tB = (float)($target['bunga'] ?? 0);
      $aB = (float)($actual['bunga'] ?? 0);

      $tD = (float)($target['denda'] ?? 0);
      $aD = (float)($actual['denda'] ?? 0);

      // achievements
      $achOs  = $tOs  > 0 ? round(($aOs  / $tOs)  * 100, 2) : 0;
      $achNoa = $tNoa > 0 ? round(($aNoa / max(1,$tNoa)) * 100, 2) : 0;
      $achB   = $tB   > 0 ? round(($aB   / $tB)   * 100, 2) : 0;
      $achD   = $tD   > 0 ? round(($aD   / $tD)   * 100, 2) : 0;

      // info stock
      $osPrev  = (float)($actual['os_npl_prev'] ?? 0);
      $osNow   = (float)($actual['os_npl_now'] ?? 0);
      $netDrop = (float)($actual['net_npl_drop'] ?? ($osPrev - $osNow));

      // skor (int)
      $sOs    = (int)($score['os'] ?? 1);
      $sNoa   = (int)($score['noa'] ?? 1);
      $sBunga = (int)($score['bunga'] ?? 1);
      $sDenda = (int)($score['denda'] ?? 1);

      // label source
      $srcLabel = strtoupper(trim((string)$src));
      if ($srcLabel === '') $srcLabel = 'REALTIME';
      $srcChip = match (strtolower($srcLabel)) {
          'YTD'      => 'bg-indigo-50 text-indigo-700 border-indigo-200',
          'MONTHLY'  => 'bg-slate-50 text-slate-700 border-slate-200',
          'REALTIME' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
          default    => 'bg-slate-50 text-slate-700 border-slate-200',
      };
    @endphp

    {{-- ✅ SKIP: jangan tampilkan WINDA (atau BE code 000033) --}}
    @if($nameUp === 'WINDA' || $code13 === '000033')
      @continue
    @endif

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">

      {{-- HEADER --}}
      <div class="px-5 py-4 border-b border-slate-200 flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <div class="text-xl font-extrabold text-slate-900 uppercase">{{ $name }}</div>

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

            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border {{ $srcChip }}">
              {{ $srcLabel }}{{ $status ? ' • '.strtoupper((string)$status) : '' }}
            </span>

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
            BE @if($code) • Code: <b>{{ $code }}</b> @endif
          </div>

          <div class="text-xs text-slate-500 mt-2 flex flex-wrap gap-2">
            <span class="{{ $chipCls }}">Recovery OS: prev kolek 3/4/5 → membaik (1/2) atau LUNAS</span>
            <!-- <span class="{{ $chipCls }}">NOA/Bunga/Denda: akumulasi (YTD) jika mode YTD</span>
            <span class="{{ $chipCls }}">OS NPL prev/now: info stock (bukan sum)</span> -->
          </div>
        </div>

        <div class="text-right shrink-0">
          <div class="text-xs text-slate-500">Total PI</div>
          <div class="text-2xl font-extrabold text-slate-900">{{ number_format($piTotal, 2) }}</div>
          <div class="text-xs text-slate-500 mt-1">
            (OS {{ (int)round($wOs*100) }}% • NOA {{ (int)round($wNoa*100) }}% • Bunga {{ (int)round($wBunga*100) }}% • Denda {{ (int)round($wDenda*100) }}%)
          </div>
        </div>
      </div>

      {{-- MINI CARDS (mobile friendly) --}}
      <div class="p-4 grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Recovery OS</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($aOs) }}</div>
          <div class="text-xs text-slate-500 mt-1">Ach {{ $fmtPct($achOs) }} • Skor {{ $sOs }}</div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">NOA Selesai</div>
          <div class="text-lg font-extrabold text-slate-900">{{ number_format($aNoa) }}</div>
          <div class="text-xs text-slate-500 mt-1">Ach {{ $fmtPct($achNoa) }} • Skor {{ $sNoa }}</div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Bunga Masuk</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($aB) }}</div>
          <div class="text-xs text-slate-500 mt-1">Ach {{ $fmtPct($achB) }} • Skor {{ $sBunga }}</div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Denda Masuk</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($aD) }}</div>
          <div class="text-xs text-slate-500 mt-1">Ach {{ $fmtPct($achD) }} • Skor {{ $sDenda }}</div>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-3 col-span-2 md:col-span-1">
          <div class="text-xs text-slate-500">NPL Stock (Info)</div>
          <div class="text-sm font-semibold text-slate-900 mt-0.5">
            Prev {{ $fmtRp($osPrev) }}
          </div>
          <div class="text-sm font-semibold text-slate-900">
            Now {{ $fmtRp($osNow) }}
          </div>
          <div class="text-xs text-slate-500 mt-1">
            Net drop {{ $fmtRp($netDrop) }}
          </div>
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
                OS Selesai (Recovery)
                <div class="text-xs text-slate-500 mt-1">
                  Info NPL stock:
                  <span class="whitespace-nowrap">Prev {{ $fmtRp($osPrev) }}</span>
                  <span class="whitespace-nowrap"> • Now {{ $fmtRp($osNow) }}</span>
                  <span class="whitespace-nowrap"> • Net drop {{ $fmtRp($netDrop) }}</span>
                </div>
              </td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tOs) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($aOs) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($achOs) }}">
                  {{ $fmtPct($achOs) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ $sOs }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wOs * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($pi['os'] ?? 0), 2) }}</td>
            </tr>

            {{-- NOA --}}
            <tr>
              <td class="px-3 py-2 font-semibold">NOA Selesai</td>
              <td class="px-3 py-2 text-right">{{ number_format($tNoa) }}</td>
              <td class="px-3 py-2 text-right">{{ number_format($aNoa) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($achNoa) }}">
                  {{ $fmtPct($achNoa) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ $sNoa }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wNoa * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($pi['noa'] ?? 0), 2) }}</td>
            </tr>

            {{-- BUNGA --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Bunga Masuk</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tB) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($aB) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($achB) }}">
                  {{ $fmtPct($achB) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ $sBunga }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wBunga * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($pi['bunga'] ?? 0), 2) }}</td>
            </tr>

            {{-- DENDA --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Denda Masuk</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tD) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($aD) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($achD) }}">
                  {{ $fmtPct($achD) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ $sDenda }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wDenda * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($pi['denda'] ?? 0), 2) }}</td>
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
      Data KPI BE belum tersedia untuk periode ini.
    </div>
  @endforelse

</div>