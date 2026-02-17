@extends('layouts.app')

@section('title', 'KPI AO - Detail')

@section('content')
@php
  $fmtRp  = fn($n) => 'Rp ' . number_format((int)($n ?? 0), 0, ',', '.');
  $fmtInt = fn($n) => number_format((int)($n ?? 0), 0, ',', '.');
  $fmt2   = fn($n) => number_format((float)($n ?? 0), 2);
  $fmtPct = fn($n) => number_format((float)($n ?? 0), 2) . '%';

  $rr = (float)($kpi->rr_pct ?? 0);

  $rrBadge = function($rr) {
    $rr = (float)($rr ?? 0);
    if ($rr >= 90) return ['label'=>'AMAN', 'cls'=>'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200'];
    if ($rr >= 80) return ['label'=>'WASPADA', 'cls'=>'bg-yellow-100 text-yellow-700 ring-1 ring-yellow-200'];
    return ['label'=>'RISIKO', 'cls'=>'bg-rose-100 text-rose-700 ring-1 ring-rose-200'];
  };

  $b = $rrBadge($rr);

  // weights (sesuaikan jika kamu punya official weight berbeda)
  $w = [
    'noa' => 0.30,
    'os'  => 0.20,
    'rr'  => 0.25,
    'community' => 0.20,
    'daily' => 0.05,
    // activity biasanya masuk ke model tertentu; di tabel kamu ada score_activity,
    // tapi kalau weight resmi berbeda, nanti kita sesuaikan.
  ];

  // convenience
  $has = !empty($kpi);
   $rrNote = '-';
@endphp

<div class="max-w-6xl mx-auto p-4 space-y-5">

  {{-- Header --}}
  <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
    <div>
      <div class="text-xs text-slate-500">Periode</div>
      <div class="text-3xl font-extrabold text-slate-900">{{ $periodLabel ?? '-' }}</div>

      <div class="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-700">
        <span class="font-extrabold uppercase">{{ $aoUser->name }}</span>
        <span class="text-slate-400">•</span>
        <span>AO Code: <b class="font-mono">{{ $aoUser->ao_code ?? '-' }}</b></span>
        <span class="text-slate-400">•</span>
        <span>Level: <b>{{ $aoUser->level ?? 'AO' }}</b></span>

        @if($has)
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] {{ $b['cls'] }}">
            RR {{ $b['label'] }} • {{ $fmt2($rr) }}%
          </span>
        @endif
      </div>

      <div class="mt-1 text-xs text-slate-500">
        Detail ini bersumber dari <b>kpi_ao_monthlies</b> untuk 1 AO pada periode terpilih.
      </div>
    </div>

    <div class="flex flex-col sm:flex-row sm:items-end gap-2">
      <a
        href="{{ route('kpi.ao.ranking', ['period' => $periodYmd]) }}"
        class="px-4 py-2 rounded-xl border border-slate-200 bg-white text-sm hover:bg-slate-50"
      >
        ← Kembali ke Ranking
      </a>

      <form method="GET" class="flex items-end gap-2">
        <div class="space-y-1">
          <div class="text-xs text-slate-500">Ganti periode</div>
          @php $periodMonth = \Carbon\Carbon::parse($periodYmd)->format('Y-m'); @endphp

            <input
            type="month"
            name="period"
            value="{{ $periodMonth }}"
            class="border border-slate-200 rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-slate-300"
            />

        </div>
        <button class="px-4 py-2 rounded-xl bg-slate-900 text-white text-sm hover:bg-slate-800">
          Terapkan
        </button>
      </form>
    </div>
  </div>

  @if(!$has)
    <div class="rounded-2xl border border-slate-200 bg-white p-6 text-slate-700">
      <div class="text-lg font-extrabold text-slate-900">Data belum tersedia</div>
      <div class="text-sm text-slate-600 mt-1">
        Tidak ada record KPI AO untuk periode <b>{{ $periodLabel }}</b>.
      </div>
      <div class="text-sm text-slate-600 mt-2">
        Pastikan proses <b>Recalc AO</b> / calculate KPI sudah menghasilkan data di <b>kpi_ao_monthlies</b>.
      </div>
    </div>
  @else

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Total PI</div>
        <div class="text-3xl font-extrabold text-slate-900">{{ $fmt2($kpi->score_total ?? 0) }}</div>
        <div class="mt-1 text-xs text-slate-500">score_total</div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mt-1 text-xs text-slate-500">
            RR% (stored): <b>{{ $fmt2($kpi->rr_pct ?? 0) }}%</b>
        </div>
        <div class="text-2xl font-extrabold text-slate-900">{{ $fmt2($kpi->rr_pct ?? 0) }}%</div>
        <div class="mt-2">
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] {{ $b['cls'] }}">
            {{ $b['label'] }}
          </span>
        </div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Realisasi Bulanan</div>
        <div class="text-xl font-extrabold text-slate-900">{{ $fmtRp($kpi->os_disbursement ?? 0) }}</div>
        <div class="text-xs text-slate-500 mt-1">OS Disbursement</div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Pertumbuhan NOA</div>
        <div class="text-xl font-extrabold text-slate-900">{{ (int)($kpi->noa_disbursement ?? 0) }}</div>
        <div class="text-xs text-slate-500 mt-1">NOA Disbursement</div>
      </div>
    </div>

    {{-- RR detail --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
      <!-- <div class="px-5 py-4 border-b border-slate-200">
        <div class="text-lg font-extrabold text-slate-900">Ringkasan RR</div>
        <div class="text-xs text-slate-500">Transparansi perhitungan RR berdasarkan data yang tersimpan.</div>
      </div> -->
      {{-- RR detail (Enterprise) --}}
        @php
            
            $osCur = (float)($kpi->rr_os_current ?? 0);
            $osTot = (float)($kpi->rr_os_total ?? 0);
            $osCov = $osTot > 0 ? ($osCur / $osTot) * 100 : 0; // coverage OS current vs total

            $rrPct = (float)($kpi->rr_pct ?? 0);

            $greenMin  = (float)($rrTh->green_min ?? 90);
            $yellowMin = (float)($rrTh->yellow_min ?? 80);

            $bRr = ['label'=>'RISIKO','cls'=>'bg-rose-100 text-rose-700 ring-1 ring-rose-200'];
            if ($rrPct >= $greenMin) $bRr = ['label'=>'AMAN','cls'=>'bg-emerald-100 text-emerald-700 ring-1 ring-emerald-200'];
            elseif ($rrPct >= $yellowMin) $bRr = ['label'=>'WASPADA','cls'=>'bg-yellow-100 text-yellow-700 ring-1 ring-yellow-200'];
        @endphp

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200 flex flex-col md:flex-row md:items-start md:justify-between gap-3">
            <div>
            <div class="text-lg font-extrabold text-slate-900">Ringkasan RR</div>
            <div class="text-xs text-slate-500">Ringkasan kualitas kredit berdasarkan RR% dan komposisi OS.</div>
            <!-- <div class="mt-2 text-sm text-slate-700">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] {{ $bRr['cls'] }}">
                RR {{ $bRr['label'] }} • {{ number_format($rrPct,2) }}%
                </span>
            </div> -->
            </div>

            <div class="text-xs text-slate-500 md:text-right">
            <div>Coverage OS Current vs Total</div>
            <div class="text-base font-extrabold text-slate-900">{{ number_format($osCov,2) }}%</div>
            </div>
        </div>

        <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">RR OS Current</div>
            <div class="text-2xl font-extrabold text-slate-900">{{ $fmtRp($kpi->rr_os_current ?? 0) }}</div>
            <div class="mt-1 text-xs text-slate-500">Portofolio yang dianggap “current” pada perhitungan RR.</div>
            </div>

            <div class="rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500">RR OS Total</div>
            <div class="text-2xl font-extrabold text-slate-900">{{ $fmtRp($kpi->rr_os_total ?? 0) }}</div>
            <div class="mt-1 text-xs text-slate-500">Total OS dalam cakupan RR.</div>
            </div>
        </div>

        <div class="px-5 pb-5 space-y-2">

        <div class="text-sm text-slate-700">
            <span class="font-semibold">Interpretasi:</span> {{ $rrNote ?? '-' }}
        </div>

        {{-- Parameter RR --}}
        <div class="text-xs text-slate-500">
            <span class="font-semibold text-slate-600">Parameter RR:</span>
            ≥ {{ number_format($greenMin,2) }}% = <span class="text-emerald-600 font-medium">AMAN</span> |
            {{ number_format($yellowMin,2) }}% – {{ number_format($greenMin - 0.01,2) }}% = <span class="text-yellow-600 font-medium">WASPADA</span> |
            < {{ number_format($yellowMin,2) }}% = <span class="text-rose-600 font-medium">RISIKO</span>
        </div>

        </div>
    </div>

    </div>

    {{-- Breakdown KPI (Target / Actual / % / Score / PI) --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
    @php
        $tNoa = (int)($kpi->target_noa_disbursement ?? $target->target_noa_disbursement ?? 0);
        $tOs  = (int)($kpi->target_os_disbursement ?? $target->target_os_disbursement ?? 0);
    @endphp

      <div class="px-5 py-4 border-b border-slate-200">
        <div class="text-lg font-extrabold text-slate-900">Breakdown KPI AO</div>
        <div class="text-xs text-slate-500">Target vs Actual vs Pencapaian dan skor yang membentuk Total PI.</div>
      </div>

      <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead class="bg-slate-900 text-white">
            <tr>
              <th class="text-left px-3 py-2">KPI</th>
              <th class="text-right px-3 py-2">Target</th>
              <th class="text-right px-3 py-2">Actual</th>
              <th class="text-right px-3 py-2">%</th>
              <th class="text-right px-3 py-2">Score</th>
              <th class="text-right px-3 py-2">Bobot</th>
              <th class="text-right px-3 py-2">PI</th>
            </tr>
          </thead>

          <tbody class="divide-y divide-slate-200">
            {{-- NOA --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Pertumbuhan NOA (Disbursement)</td>
              <td class="px-3 py-2 text-right">{{ (int)($target->target_noa_disbursement ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($kpi->noa_disbursement ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($kpi->noa_disbursement_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-right font-bold">{{ $fmt2($kpi->score_noa ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($w['noa']*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ $fmt2($kpi->pi_noa ?? 0) }}</td>
            </tr>

            {{-- OS --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Realisasi Bulanan (OS Disbursement)</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($target->target_os_disbursement ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($kpi->os_disbursement ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($kpi->os_disbursement_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-right font-bold">{{ $fmt2($kpi->score_os ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($w['os']*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ $fmt2($kpi->pi_os ?? 0) }}</td>
            </tr>

            {{-- RR --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Kualitas Kredit (RR)</td>
              <td class="px-3 py-2 text-right">{{ $fmt2($target->target_rr ?? 100) }}%</td>
              <td class="px-3 py-2 text-right">{{ $fmt2($kpi->rr_pct ?? 0) }}%</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($kpi->rr_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-right font-bold">{{ $fmt2($kpi->score_rr ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($w['rr']*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ $fmt2($kpi->pi_rr ?? 0) }}</td>
            </tr>

            {{-- Community --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Grab to Community</td>
              <td class="px-3 py-2 text-right">{{ (int)($target->target_community ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($kpi->community_actual ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($kpi->community_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-right font-bold">{{ $fmt2($kpi->score_community ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($w['community']*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ $fmt2($kpi->pi_community ?? 0) }}</td>
            </tr>

            {{-- Daily --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Daily Report</td>
              <td class="px-3 py-2 text-right">{{ (int)($target->target_daily_report ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($kpi->daily_report_actual ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($kpi->daily_report_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-right font-bold">{{ $fmt2($kpi->score_daily_report ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($w['daily']*100) }}%</td>
              <td class="px-3 py-2 text-right font-extrabold">{{ $fmt2($kpi->pi_daily ?? 0) }}</td>
            </tr>

            <!-- {{-- Activity (ditampilkan, tapi bobot belum kita masukkan ke total sampai weight resmi kamu pastikan) --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Activity (monitoring)</td>
              <td class="px-3 py-2 text-right">{{ (int)($kpi->activity_target ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($kpi->activity_actual ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($kpi->activity_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-right font-bold">{{ $fmt2($kpi->score_activity ?? 0) }}</td>
              <td class="px-3 py-2 text-right">-</td>
              <td class="px-3 py-2 text-right font-extrabold">-</td>
            </tr>

            {{-- Kolek (monitoring) --}}
            <tr>
              <td class="px-3 py-2 font-semibold">Kolektibilitas (monitoring)</td>
              <td class="px-3 py-2 text-right">-</td>
              <td class="px-3 py-2 text-right">{{ $fmtRp($kpi->os_npl_migrated ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $fmtPct($kpi->npl_migration_pct ?? 0) }}</td>
              <td class="px-3 py-2 text-right font-bold">{{ $fmt2($kpi->score_kolek ?? 0) }}</td>
              <td class="px-3 py-2 text-right">-</td>
              <td class="px-3 py-2 text-right font-extrabold">-</td>
            </tr> -->
          </tbody>

          <tfoot>
            <tr class="bg-yellow-200">
              <td colspan="6" class="px-3 py-2 font-extrabold text-right">TOTAL</td>
              <td class="px-3 py-2 font-extrabold text-right">
                {{ $fmt2($kpi->score_total ?? 0) }}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="px-5 pb-5 text-xs text-slate-500">
        Catatan: komponen “Activity” & “Kolek” ditampilkan sebagai monitoring transparansi.
        Jika bobot resmi memasukkan komponen tersebut ke total PI, nanti kita sesuaikan agar PI-nya juga muncul.
      </div>
    </div>

    {{-- OS & NOA movement (optional but useful) --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm font-extrabold text-slate-900">OS Movement</div>
        <div class="mt-2 text-sm text-slate-700">Opening: <b>{{ $fmtRp($kpi->os_opening ?? 0) }}</b></div>
        <div class="text-sm text-slate-700">Closing: <b>{{ $fmtRp($kpi->os_closing ?? 0) }}</b></div>
        <div class="text-sm text-slate-700">Growth: <b>{{ $fmtRp($kpi->os_growth ?? 0) }}</b></div>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="text-sm font-extrabold text-slate-900">NOA Movement</div>
        <div class="mt-2 text-sm text-slate-700">Opening: <b>{{ (int)($kpi->noa_opening ?? 0) }}</b></div>
        <div class="text-sm text-slate-700">Closing: <b>{{ (int)($kpi->noa_closing ?? 0) }}</b></div>
        <div class="text-sm text-slate-700">Growth: <b>{{ (int)($kpi->noa_growth ?? 0) }}</b></div>
      </div>
    </div>

  @endif
</div>
@endsection
