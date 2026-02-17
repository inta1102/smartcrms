@php
  $rows = $rows ?? collect();

  $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $dec=2) => number_format((float)($n ?? 0), $dec) . '%';

  $role = strtoupper(trim((string)$role));
@endphp
@php
  $rows = $rows ?? collect();

  // pastikan collection
  if (!($rows instanceof \Illuminate\Support\Collection)) $rows = collect($rows);

  $fmtRp = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $dec=2) => number_format((float)($n ?? 0), $dec, ',', '.') . '%';

  $role = strtoupper(trim((string)$role));

  // =========================
  // TLFE Scope Insights
  // =========================
  // Anggap field FE sudah ada (kalau belum, aman: default 0)
  $scoreField = $role === 'FE' ? 'total_score_weighted' : 'score_total'; // fleksibel
  $scores = $rows->map(fn($r)=>(float)($r->$scoreField ?? $r->score_total ?? 0))->filter(fn($x)=>$x>0);

  $countAll = $rows->count();
  $countHasScore = $scores->count();
  $avgScore = $countHasScore ? $scores->avg() : 0;
  $minScore = $countHasScore ? $scores->min() : 0;
  $maxScore = $countHasScore ? $scores->max() : 0;

  // kategori sederhana untuk coaching priority
  $critical = $rows->filter(fn($r)=> (float)($r->$scoreField ?? $r->score_total ?? 0) > 0
                              && (float)($r->$scoreField ?? $r->score_total ?? 0) < 2.5);
  $warning  = $rows->filter(fn($r)=> (float)($r->$scoreField ?? $r->score_total ?? 0) >= 2.5
                              && (float)($r->$scoreField ?? $r->score_total ?? 0) < 3.5);
  $onTrack  = $rows->filter(fn($r)=> (float)($r->$scoreField ?? $r->score_total ?? 0) >= 3.5);

  // agregat metrik (kalau field ada di query FE)
  $sumOsTurun  = $rows->sum(fn($r)=>(float)($r->os_kol2_turun_total ?? 0));
  $sumPenalty  = $rows->sum(fn($r)=>(float)($r->penalty_paid_total ?? 0));
  $avgMigrasi  = $rows->count() ? $rows->avg(fn($r)=>(float)($r->migrasi_npl_pct ?? 0)) : 0;

  // kualitas recovery: penalty vs os turun (indikasi bayar penalty doang)
  $penaltyToOs = ($sumOsTurun > 0) ? ($sumPenalty / $sumOsTurun) : null;

  // Top 3 FE bermasalah (score paling rendah tapi punya score)
  $prio = $rows->filter(fn($r)=>(float)($r->$scoreField ?? $r->score_total ?? 0) > 0)
              ->sortBy(fn($r)=>(float)($r->$scoreField ?? $r->score_total ?? 0))
              ->take(5)
              ->values();

  // helper badge
  $badgeScore = function($s){
    $s = (float)$s;
    if ($s <= 0) return ['N/A','bg-slate-100 text-slate-700 border-slate-200'];
    if ($s < 2.5) return ['Critical','bg-rose-100 text-rose-800 border-rose-200'];
    if ($s < 3.5) return ['Warning','bg-amber-100 text-amber-800 border-amber-200'];
    return ['On Track','bg-emerald-100 text-emerald-800 border-emerald-200'];
  };

  // auto insight bullets TL
  $insightBullets = [];
  $insightBullets[] = "Rata-rata skor scope: " . number_format($avgScore, 2, ',', '.') .
                      " (min " . number_format($minScore,2,',','.') . " – max " . number_format($maxScore,2,',','.') . ").";

  if ($critical->count() > 0) {
    $insightBullets[] = "Ada {$critical->count()} FE kategori Critical (butuh coaching prioritas).";
  } else {
    $insightBullets[] = "Tidak ada FE kategori Critical (bagus, tinggal jaga konsistensi).";
  }

  // migrasi NPL (semakin kecil semakin baik)
  if ($avgMigrasi > 2.0) {
    $insightBullets[] = "Rata-rata migrasi NPL cukup tinggi (" . $fmtPct($avgMigrasi) . "). Fokus: quality control & early action.";
  } else {
    $insightBullets[] = "Rata-rata migrasi NPL relatif terkendali (" . $fmtPct($avgMigrasi) . ").";
  }

  // penalty ratio
  if (!is_null($penaltyToOs)) {
    $ratioPct = $penaltyToOs * 100;
    if ($ratioPct > 25) {
      $insightBullets[] = "Rasio penalty/OS turun tinggi (" . number_format($ratioPct,2,',','.') . "%). Indikasi: banyak bayar denda, pokok belum turun.";
    } else {
      $insightBullets[] = "Rasio penalty/OS turun normal (" . number_format($ratioPct,2,',','.') . "%).";
    }
  }
@endphp

{{-- =========================
    TLFE Scope Summary
   ========================= --}}
@if(str_starts_with($role,'TL') || $role==='FE')
  <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-4">
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-sm text-slate-500">Scope FE</div>
      <div class="text-4xl font-black text-slate-900 mt-1">{{ $countAll }}</div>
      <div class="text-xs text-slate-500 mt-2">Memiliki skor: <b>{{ $countHasScore }}</b></div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-sm text-slate-500">Rata-rata Skor</div>
      <div class="text-4xl font-black text-slate-900 mt-1">{{ number_format($avgScore, 2, '.', '') }}</div>
      <div class="text-xs text-slate-500 mt-2">
        Critical: <b>{{ $critical->count() }}</b> · Warning: <b>{{ $warning->count() }}</b> · On Track: <b>{{ $onTrack->count() }}</b>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-sm text-slate-500">Total OS Turun (Kol2+)</div>
      <div class="text-2xl font-black text-slate-900 mt-1">{{ $fmtRp($sumOsTurun) }}</div>
      <div class="text-xs text-slate-500 mt-2">Akumulasi scope untuk periode ini.</div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-sm text-slate-500">Migrasi NPL (Avg)</div>
      <div class="text-2xl font-black text-slate-900 mt-1">{{ $fmtPct($avgMigrasi) }}</div>
      <div class="text-xs text-slate-500 mt-2">
        Penalty total: <b>{{ $fmtRp($sumPenalty) }}</b>
      </div>
    </div>
  </div>

  {{-- Interpretasi TL (lebih cerdas, bukan duplikasi kartu) --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden mb-4">
    <div class="p-4 border-b border-slate-200">
      <div class="text-xl font-bold text-slate-900">Insight TL (Scope)</div>
      <div class="text-sm text-slate-500 mt-1">Ringkasan cepat untuk menentukan coaching & prioritas aksi.</div>
    </div>
    <div class="p-4">
      <ul class="list-disc pl-5 space-y-2 text-slate-700">
        @foreach($insightBullets as $b)
          <li>{{ $b }}</li>
        @endforeach
      </ul>
    </div>
  </div>

  {{-- Prioritas coaching --}}
  @if($prio->count() > 0)
    <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 mb-4">
      <div class="font-bold text-rose-900">Prioritas Coaching (Skor Terendah)</div>
      <div class="text-sm text-rose-800 mt-1">
        Fokus dulu ke 3–5 FE terendah agar dampak scope cepat membaik.
      </div>
      <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2">
        @foreach($prio as $p)
          @php
            $s = (float)($p->$scoreField ?? $p->score_total ?? 0);
            [$lbl,$cls] = $badgeScore($s);
          @endphp
          <div class="rounded-xl border border-rose-200 bg-white p-3 flex items-center justify-between">
            <div>
              <div class="font-semibold text-slate-900">{{ $p->name ?? '-' }}</div>
              <div class="text-xs text-slate-500">
                OS turun: {{ $fmtRp($p->os_kol2_turun_total ?? 0) }} · Migrasi: {{ $fmtPct($p->migrasi_npl_pct ?? 0) }}
              </div>
            </div>
            <div class="text-right">
              <div class="font-black text-slate-900">{{ number_format($s, 2, '.', '') }}</div>
              <span class="text-xs px-2 py-1 rounded-full border {{ $cls }}">{{ $lbl }}</span>
            </div>
          </div>
        @endforeach
      </div>
    </div>
  @endif
@endif

@if($role==='RO' || $role==='SO' || $role==='AO'  )
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900">Daftar Ranking</div>
      <div class="text-xs text-slate-500">
        Urut: Total Score ({{ $role }}) @if($role==='RO') – Weighted RO @endif
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-600">
            <th class="p-3 text-left w-16">Rank</th>
            <th class="p-3 text-left">{{ $role }}</th>

            {{-- kolom umum --}}
            <th class="p-3 text-right">Total</th>

            {{-- kolom per role --}}
            @if($role==='RO')
              <th class="p-3 text-right">Repay %</th>
              <th class="p-3 text-right">TopUp</th>
              <th class="p-3 text-right">NOA</th>
              <th class="p-3 text-right">DPK %</th>
              <th class="p-3 text-right">Migrasi</th>

            @elseif($role==='AO')
              <th class="p-3 text-right">OS Disb</th>
              <th class="p-3 text-right">NOA Disb</th>
              <th class="p-3 text-right">RR</th>
              <th class="p-3 text-right">OS Score</th>
              <th class="p-3 text-right">NOA Score</th>

            @elseif($role==='SO')
              <th class="p-3 text-right">OS Disb</th>
              <th class="p-3 text-right">NOA Disb</th>
              <th class="p-3 text-right">RR</th>
              <th class="p-3 text-right">OS Score</th>
              <th class="p-3 text-right">NOA Score</th>

            @elseif($role==='FE')
              <th class="p-3 text-right">PI OS</th>
              <th class="p-3 text-right">PI Migrasi</th>
              <th class="p-3 text-right">PI Denda</th>
              <th class="p-3 text-right">PI Total</th>

            @elseif($role==='BE')
              <th class="p-3 text-right">Catatan</th>

            @else
              <th class="p-3 text-right">OS</th>
              <th class="p-3 text-right">NOA</th>
            @endif
          </tr>
        </thead>

        <tbody class="divide-y">
          @forelse($rows as $r)
            @php
              [$uid,$name,$aoCode] = $resolveRow($r);
            @endphp

            <tr class="hover:bg-slate-50">
              <td class="p-3 font-semibold">{{ $r->rank ?? '-' }}</td>

              <td class="p-3">
                <div class="font-semibold text-slate-900">{{ $name }}</div>
                <div class="text-xs text-slate-500">{{ $role }} Code: {{ $aoCode }}</div>
              </td>

              <td class="p-3 text-right font-bold">
                {{ number_format((float)($r->score_total ?? 0), 2) }}
              </td>

              @if($role==='RO')
                <td class="p-3 text-right">{{ $fmtPct($r->repayment_pct ?? 0, 2) }}</td>
                <td class="p-3 text-right">{{ $fmtRp($r->topup_realisasi ?? 0) }}</td>
                <td class="p-3 text-right">{{ number_format((int)($r->noa_realisasi ?? 0)) }}</td>
                <td class="p-3 text-right">{{ $fmtPct($r->dpk_pct ?? 0, 4) }}</td>
                <td class="p-3 text-right">
                  <div>{{ number_format((int)($r->dpk_migrasi_count ?? 0)) }}</div>
                  <div class="text-xs text-slate-500">{{ $fmtRp($r->dpk_migrasi_os ?? 0) }}</div>
                </td>

              @elseif($role==='AO')
                  <td class="p-3 text-right">{{ $fmtRp($r->os_disb ?? 0) }}</td>
                  <td class="p-3 text-right">{{ number_format((int)($r->noa_disb ?? 0)) }}</td>
                  <td class="p-3 text-right">{{ $fmtPct($r->rr_pct ?? 0, 2) }}</td>
                  <td class="p-3 text-right">{{ number_format((float)($r->score_os ?? 0), 2) }}</td>
                  <td class="p-3 text-right">{{ number_format((float)($r->score_noa ?? 0), 2) }}</td>

              @elseif($role==='SO')
                <td class="p-3 text-right">{{ $fmtRp($r->os_disb ?? 0) }}</td>
                <td class="p-3 text-right">{{ number_format((int)($r->noa_disb ?? 0)) }}</td>
                <td class="p-3 text-right">{{ $fmtPct($r->rr_pct ?? 0, 2) }}</td>
                <td class="p-3 text-right">{{ number_format((float)($r->score_os ?? 0), 2) }}</td>
                <td class="p-3 text-right">{{ number_format((float)($r->score_noa ?? 0), 2) }}</td>

              @elseif($role==='FE')
                <!-- <td class="px-3 py-2 text-right">{{ number_format((float)($r->pi_os ?? 0), 2) }}</td>
                <td class="px-3 py-2 text-right">{{ number_format((float)($r->pi_mg ?? 0), 2) }}</td>
                <td class="px-3 py-2 text-right">{{ number_format((float)($r->pi_pen ?? 0), 2) }}</td>

                <td class="px-3 py-2 text-right font-extrabold text-slate-900">
                    {{ number_format((float)($r->pi_total ?? 0), 2) }}
                </td> -->

              @elseif($role==='BE')
                <td class="p-3 text-right text-slate-500">Score BE terisi, detail metrik akan kita tampilkan setelah field BE di-expose di query.</td>

              @else
                <td class="p-3 text-right">{{ $fmtRp($r->os_growth ?? 0) }}</td>
                <td class="p-3 text-right">{{ number_format((int)($r->noa_growth ?? 0)) }}</td>
              @endif
            </tr>

          @empty
            <tr>
              <td colspan="12" class="p-6 text-center text-slate-500">
                Data belum tersedia.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
 @endif
</div>
