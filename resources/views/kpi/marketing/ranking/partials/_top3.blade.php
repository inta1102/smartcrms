@php
  $fmtRp = function($n){
    return 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  };

  $fmtPct = function($n, $dec=2){
    return number_format((float)($n ?? 0), $dec) . '%';
  };

  /**
   * Ambil 2 metrik “headline” per role untuk kartu Top3.
   * Return: [label1, value1, label2, value2]
   */
  $top3Meta = function(string $role, string $tab, $r) use ($fmtRp, $fmtPct) {
    $role = strtoupper(trim($role));
    $tab  = strtolower(trim($tab));

    // ========= TAB SCORE =========
    if ($tab === 'score') {
      if ($role === 'RO') {
        return [
          'TopUp', $fmtRp($r->topup_realisasi ?? 0),
          'Repay', $fmtPct($r->repayment_pct ?? 0, 2),
        ];
      }

      if ($role === 'AO') {
        return [
          'OS Disb', $fmtRp($r->os_disb ?? 0),          // dari controller: os_growth = os_disbursement
          'NOA Disb', number_format((int)($r->noa_disb ?? 0)),
        ];
      }

      if ($role === 'SO') {
        return [
          'OS Disb', $fmtRp($r->os_disb ?? 0),
          'RR', $fmtPct($r->rr_pct ?? 0, 2),
        ];
      }

      if ($role === 'FE') {
        // FE metrik detail belum kamu expose di controller ranking,
        // tapi minimal Top3 tetap hidup pakai total score.
        return [
          'Kinerja', 'Skor',
          'Status', 'FE',
        ];
      }

      if ($role === 'BE') {
        return [
          'Kinerja', 'Skor',
          'Status', 'BE',
        ];
      }

      // fallback
      return [
        'Metrik 1', '-',
        'Metrik 2', '-',
      ];
    }

    // ========= TAB GROWTH =========
    // growth non-RO: snapshot OS/NOA growth; RO growth: topup/noa realisasi
    if ($role === 'RO') {
      return [
        'TopUp', $fmtRp($r->os_growth ?? 0),
        'NOA', number_format((int)($r->noa_growth ?? 0)),
      ];
    }

    return [
      'OS Growth', $fmtRp($r->os_growth ?? 0),
      'NOA Growth', number_format((int)($r->noa_growth ?? 0)),
    ];
  };
@endphp

<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
  @for($i=0; $i<3; $i++)
    @php $r = $top[$i] ?? null; @endphp

    <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="text-xs text-slate-500">{{ $labels[$i] }}</div>

      @if($r)
        @php
          [$uid,$name,$aoCode] = $resolveRow($r);
          [$m1l,$m1v,$m2l,$m2v] = $top3Meta($role, (string)$tab, $r);
        @endphp

        <div class="mt-1 text-lg font-bold text-slate-900">
          {{ $name }}
        </div>
        <div class="text-xs text-slate-500">
          {{ $role }} Code: <b>{{ $aoCode }}</b>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2">
          <!-- <div class="rounded-xl bg-slate-50 p-2">
            <div class="text-[11px] text-slate-500">Total Score</div>
            <div class="font-bold">{{ number_format((float)($r->score_total ?? 0),2) }}</div>
          </div> -->

          <div class="rounded-xl bg-slate-50 p-2">
            <div class="text-[11px] text-slate-500">{{ $m1l }}</div>
            <div class="font-bold">{{ $m1v }}</div>
          </div>

          <div class="rounded-xl bg-slate-50 p-2 col-span-2">
            <div class="text-[11px] text-slate-500">{{ $m2l }}</div>
            <div class="font-bold">{{ $m2v }}</div>
          </div>
        </div>

      @else
        <div class="mt-2 text-sm text-slate-500">Belum ada data.</div>
      @endif
    </div>
  @endfor
</div>
