@php
  $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $d=2) => number_format((float)($n ?? 0), $d) . '%';

  $badge = function($ach){
      $ach = (float)($ach ?? 0);
      if ($ach >= 100) return 'bg-emerald-100 text-emerald-800 border-emerald-200';
      if ($ach >= 80)  return 'bg-amber-100 text-amber-800 border-amber-200';
      return 'bg-rose-100 text-rose-800 border-rose-200';
  };

  $wOs    = (float)($weights['os'] ?? 0.50);
  $wNoa   = (float)($weights['noa'] ?? 0.10);
  $wBunga = (float)($weights['bunga'] ?? 0.20);
  $wDenda = (float)($weights['denda'] ?? 0.20);

  $recap = $recap ?? null;
@endphp

<div class="space-y-6">

  @if(!$recap)
    <div class="rounded-2xl border border-slate-200 bg-white p-6 text-slate-600">
      Data TLBE belum tersedia untuk periode ini (scope kosong / BE belum terhitung).
    </div>
  @else
    {{-- HEADER --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <div class="px-5 py-4 border-b border-slate-200 flex flex-col md:flex-row md:items-start md:justify-between gap-4">
        <div class="min-w-0">
          <div class="flex flex-wrap items-center gap-2">
            <div class="text-lg font-extrabold text-slate-900 uppercase">
              KPI TLBE – {{ $recap['name'] ?? 'TLBE' }}
            </div>

            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200">
              Scope: {{ (int)($recap['scope_count'] ?? 0) }} BE
            </span>

            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border bg-indigo-50 text-indigo-700 border-indigo-200">
              Period: {{ \Carbon\Carbon::parse($recap['period'])->format('M Y') }}
            </span>

            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold border bg-emerald-50 text-emerald-700 border-emerald-200">
              LEADERSHIP INDEX ON
            </span>
          </div>

          <div class="text-xs text-slate-500 mt-2 flex flex-wrap gap-2">
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200">
              Team Performance = agregasi target vs actual scope
            </span>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200">
              Coverage = % BE PI ≥ 3.00
            </span>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold border bg-slate-50 text-slate-700 border-slate-200">
              Consistency = 1 - deviasi PI tim
            </span>
          </div>
        </div>

        <div class="text-right shrink-0">
          <div class="text-xs text-slate-500">Total PI TLBE</div>
          <div class="text-3xl font-extrabold text-slate-900">
            {{ number_format((float)($recap['pi']['total'] ?? 0), 2) }}
          </div>
          <div class="text-xs text-slate-500 mt-1">
            (OS {{ (int)round($wOs*100) }}% • NOA {{ (int)round($wNoa*100) }}% •
            Bunga {{ (int)round($wBunga*100) }}% • Denda {{ (int)round($wDenda*100) }}%)
          </div>
        </div>
      </div>

      {{-- MINI CARDS --}}
      @php
        $ach = $recap['ach_pct'] ?? [];
        $t = $recap['target_sum'] ?? [];
        $a = $recap['actual_sum'] ?? [];
        $L = $recap['leadership'] ?? [];
      @endphp

      <div class="p-4 grid grid-cols-2 md:grid-cols-5 gap-3">
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Recovery OS (Sum)</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($a['os'] ?? 0) }}</div>
          <div class="text-xs text-slate-500 mt-1">
            Ach <span class="font-semibold">{{ $fmtPct($ach['os'] ?? 0) }}</span>
            • Target {{ $fmtRp($t['os'] ?? 0) }}
          </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">NOA Selesai (Sum)</div>
          <div class="text-lg font-extrabold text-slate-900">{{ (int)($a['noa'] ?? 0) }}</div>
          <div class="text-xs text-slate-500 mt-1">
            Ach <span class="font-semibold">{{ $fmtPct($ach['noa'] ?? 0) }}</span>
            • Target {{ (int)($t['noa'] ?? 0) }}
          </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Bunga Masuk (Sum)</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($a['bunga'] ?? 0) }}</div>
          <div class="text-xs text-slate-500 mt-1">
            Ach <span class="font-semibold">{{ $fmtPct($ach['bunga'] ?? 0) }}</span>
            • Target {{ $fmtRp($t['bunga'] ?? 0) }}
          </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Denda Masuk (Sum)</div>
          <div class="text-lg font-extrabold text-slate-900">{{ $fmtRp($a['denda'] ?? 0) }}</div>
          <div class="text-xs text-slate-500 mt-1">
            Ach <span class="font-semibold">{{ $fmtPct($ach['denda'] ?? 0) }}</span>
            • Target {{ $fmtRp($t['denda'] ?? 0) }}
          </div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-3">
          <div class="text-xs text-slate-500">Leadership Summary</div>
          <div class="text-sm font-bold text-slate-900">
            Avg PI BE: {{ number_format((float)($L['avg_pi_be'] ?? 0), 2) }}
          </div>
          <div class="text-xs text-slate-500 mt-1">
            Coverage: {{ $fmtPct($L['coverage_pct'] ?? 0) }} •
            Consistency: {{ number_format((float)($L['consistency_idx'] ?? 0), 4) }}
          </div>
        </div>
      </div>

      {{-- TABLE: KPI TL --}}
      <div class="px-4 pb-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-900 text-white">
            <tr>
              <th class="text-left px-3 py-2">KPI (Team)</th>
              <th class="text-right px-3 py-2">Target (Sum)</th>
              <th class="text-right px-3 py-2">Actual (Sum)</th>
              <th class="text-right px-3 py-2">Pencapaian</th>
              <th class="text-center px-3 py-2">Skor</th>
              <th class="text-right px-3 py-2">Bobot</th>
              <th class="text-right px-3 py-2">PI</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-200">
            {{-- OS --}}
            <tr>
              <td class="px-3 py-2 font-semibold">OS Selesai (Recovery) – Team</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($t['os'] ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($a['os'] ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($ach['os'] ?? 0) }}">
                  {{ $fmtPct($ach['os'] ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($recap['score']['os'] ?? 1) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wOs*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($recap['pi']['os'] ?? 0), 2) }}</td>
            </tr>

            {{-- NOA --}}
            <tr>
              <td class="px-3 py-2 font-semibold">NOA Selesai – Team</td>
              <td class="px-3 py-2 text-right">{{ (int)($t['noa'] ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($a['noa'] ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($ach['noa'] ?? 0) }}">
                  {{ $fmtPct($ach['noa'] ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($recap['score']['noa'] ?? 1) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wNoa*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($recap['pi']['noa'] ?? 0), 2) }}</td>
            </tr>

            {{-- Bunga --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Bunga Masuk – Team</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($t['bunga'] ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($a['bunga'] ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($ach['bunga'] ?? 0) }}">
                  {{ $fmtPct($ach['bunga'] ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($recap['score']['bunga'] ?? 1) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wBunga*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($recap['pi']['bunga'] ?? 0), 2) }}</td>
            </tr>

            {{-- Denda --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Denda Masuk – Team</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($t['denda'] ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($a['denda'] ?? 0) }}</td>
              <td class="px-3 py-2 text-right">
                <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold {{ $badge($ach['denda'] ?? 0) }}">
                  {{ $fmtPct($ach['denda'] ?? 0) }}
                </span>
              </td>
              <td class="px-3 py-2 text-center font-bold">{{ (int)($recap['score']['denda'] ?? 1) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)round($wDenda*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ number_format((float)($recap['pi']['denda'] ?? 0), 2) }}</td>
            </tr>
          </tbody>

          <tfoot>
            <tr class="bg-yellow-200">
              <td colspan="6" class="px-3 py-2 font-extrabold text-right">TEAM PI</td>
              <td class="px-3 py-2 font-extrabold text-right">{{ number_format((float)($recap['pi']['team'] ?? 0), 2) }}</td>
            </tr>
            <tr class="bg-yellow-100">
              <td colspan="6" class="px-3 py-2 font-extrabold text-right">TOTAL PI TLBE (Leadership)</td>
              <td class="px-3 py-2 font-extrabold text-right">{{ number_format((float)($recap['pi']['total'] ?? 0), 2) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      {{-- RANKING BE SCOPE --}}
      <div class="px-5 pb-5">
        <div class="flex items-center justify-between mb-3">
          <div class="text-sm font-extrabold text-slate-900 uppercase">Ranking BE (Scope)</div>
          <div class="text-xs text-slate-500">Urut berdasarkan <b>Total PI BE</b></div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50">
              <tr>
                <th class="text-left px-3 py-2 w-14">#</th>
                <th class="text-left px-3 py-2">BE</th>
                <th class="text-left px-3 py-2">Code</th>
                <th class="text-right px-3 py-2">PI OS</th>
                <th class="text-right px-3 py-2">PI NOA</th>
                <th class="text-right px-3 py-2">PI Bunga</th>
                <th class="text-right px-3 py-2">PI Denda</th>
                <th class="text-right px-3 py-2 font-extrabold">Total PI</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 bg-white">
              @foreach(($rankings ?? []) as $r)
                @php
                  $rank = (int)($r['rank'] ?? 0);
                  $isTop = $rank > 0 && $rank <= 3;
                  $rowClass = $isTop ? 'bg-emerald-50/40' : '';
                @endphp
                <tr class="{{ $rowClass }}">
                  <td class="px-3 py-2 font-bold text-slate-900">{{ $rank }}</td>
                  <td class="px-3 py-2">
                    <div class="font-semibold text-slate-900">{{ $r['name'] ?? '-' }}</div>
                    <div class="text-xs text-slate-500">
                      Source: {{ strtoupper((string)($r['source'] ?? '')) }}
                    </div>
                  </td>
                  <td class="px-3 py-2 font-mono text-slate-700">{{ $r['code'] ?? ($r['ao_code'] ?? '-') }}</td>
                  <td class="px-3 py-2 text-right">{{ number_format((float)($r['pi']['os'] ?? 0), 2) }}</td>
                  <td class="px-3 py-2 text-right">{{ number_format((float)($r['pi']['noa'] ?? 0), 2) }}</td>
                  <td class="px-3 py-2 text-right">{{ number_format((float)($r['pi']['bunga'] ?? 0), 2) }}</td>
                  <td class="px-3 py-2 text-right">{{ number_format((float)($r['pi']['denda'] ?? 0), 2) }}</td>
                  <td class="px-3 py-2 text-right font-extrabold text-slate-900">{{ number_format((float)($r['pi']['total'] ?? 0), 2) }}</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>

        <div class="mt-2 text-xs text-slate-500">
          Catatan: Top 3 diberi highlight. Coverage & consistency dipakai untuk menilai kekuatan tim (bukan cuma 1 orang).
        </div>
      </div>
    </div>
  @endif

</div>