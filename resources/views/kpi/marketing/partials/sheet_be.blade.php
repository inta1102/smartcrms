@php
  $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n) => number_format((float)($n ?? 0), 2) . '%';

  $badge = function($ach){
      $ach = (float)($ach ?? 0);
      if ($ach >= 100) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
      if ($ach >= 80)  return 'bg-amber-50 text-amber-700 border-amber-200';
      return 'bg-rose-50 text-rose-700 border-rose-200';
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

      $src = $isObj ? ($it->source ?? 'realtime') : ($it['source'] ?? 'realtime');
      $status = $isObj ? ($it->status ?? null) : ($it['status'] ?? null);
    @endphp

    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-4">
        <div>
          <div class="text-xl font-extrabold text-slate-900 uppercase">{{ $name }}</div>
          <div class="text-sm text-slate-600 mt-1">
            BE
            @if($code) • Code: <b>{{ $code }}</b> @endif
            <span class="ml-2 text-xs text-slate-500">
              ({{ strtoupper((string)$src) }}{{ $status ? ' • '.strtoupper((string)$status) : '' }})
            </span>
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

            {{-- OS --}}
            @php
              $tOs = (float)($target['os'] ?? 0);
              $aOs = (float)($actual['os'] ?? 0);
              $achOs = $tOs > 0 ? round(($aOs / $tOs) * 100, 2) : 0;
            @endphp
            <tr>
              <td class="px-3 py-2 font-semibold">
                OS Selesai (Recovery)
                <div class="text-xs text-slate-500 mt-1">
                  Info: OS NPL prev {{ $fmtRp($actual['os_npl_prev'] ?? 0) }}
                  • OS NPL now {{ $fmtRp($actual['os_npl_now'] ?? 0) }}
                  • Net drop {{ $fmtRp($actual['net_npl_drop'] ?? 0) }}
                </div>
              </td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tOs) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($aOs) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-1 rounded-full border text-xs font-semibold {{ $badge($achOs) }}">
                  {{ $fmtPct($achOs) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($score['os'] ?? 1), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wOs * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($pi['os'] ?? 0), 2) }}</td>
            </tr>

            {{-- NOA --}}
            @php
              $tNoa = (int)($target['noa'] ?? 0);
              $aNoa = (int)($actual['noa'] ?? 0);
              $achNoa = $tNoa > 0 ? round(($aNoa / $tNoa) * 100, 2) : 0;
            @endphp
            <tr>
              <td class="px-3 py-2 font-semibold">NOA Selesai</td>
              <td class="px-3 py-2 text-right">{{ $tNoa }}</td>
              <td class="px-3 py-2 text-right">{{ $aNoa }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-1 rounded-full border text-xs font-semibold {{ $badge($achNoa) }}">
                  {{ $fmtPct($achNoa) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($score['noa'] ?? 1), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wNoa * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($pi['noa'] ?? 0), 2) }}</td>
            </tr>

            {{-- BUNGA --}}
            @php
              $tB = (float)($target['bunga'] ?? 0);
              $aB = (float)($actual['bunga'] ?? 0);
              $achB = $tB > 0 ? round(($aB / $tB) * 100, 2) : 0;
            @endphp
            <tr>
              <td class="px-3 py-2 font-semibold">Bunga Masuk</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tB) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($aB) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-1 rounded-full border text-xs font-semibold {{ $badge($achB) }}">
                  {{ $fmtPct($achB) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($score['bunga'] ?? 1), 2) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wBunga * 100) }}%</td>
              <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($pi['bunga'] ?? 0), 2) }}</td>
            </tr>

            {{-- DENDA --}}
            @php
              $tD = (float)($target['denda'] ?? 0);
              $aD = (float)($actual['denda'] ?? 0);
              $achD = $tD > 0 ? round(($aD / $tD) * 100, 2) : 0;
            @endphp
            <tr>
              <td class="px-3 py-2 font-semibold">Denda Masuk</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($tD) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($aD) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-2 py-1 rounded-full border text-xs font-semibold {{ $badge($achD) }}">
                  {{ $fmtPct($achD) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ number_format((float)($score['denda'] ?? 1), 2) }}</td>
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
