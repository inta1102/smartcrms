@extends('layouts.app')

@section('content')
@php
  use Illuminate\Support\Str;

  $fmt = fn($n, $d=2) => number_format((float)($n ?? 0), $d, ',', '.');

  // urutan role yang enak dilihat (kamu bisa ubah)
  $roleOrder = [
    'RO' => 10, 'SO' => 20, 'AO' => 30, 'FE' => 40, 'BE' => 50,
    'TLRO' => 60, 'TLUM' => 70, 'TLFE' => 80,
    'KSLR' => 90, 'KSBE' => 100, 'KSFE' => 110,
    'KBL'  => 120,
  ];

  $levelOrder = ['STAFF' => 10, 'TL' => 20, 'KASI' => 30, 'KABAG' => 40];

  // rows -> collection biar gampang diolah
  $rowsCol = collect($rows ?? [])
    ->map(function($r) {
      $r['level'] = strtoupper((string)($r['level'] ?? ''));
      $r['role']  = strtoupper((string)($r['role'] ?? ''));
      return $r;
    })
    // sorting global: level -> role -> rank -> score desc
    ->sort(function($a, $b) use ($levelOrder, $roleOrder) {
      $la = $levelOrder[$a['level'] ?? ''] ?? 999;
      $lb = $levelOrder[$b['level'] ?? ''] ?? 999;
      if ($la !== $lb) return $la <=> $lb;

      $ra = $roleOrder[$a['role'] ?? ''] ?? 999;
      $rb = $roleOrder[$b['role'] ?? ''] ?? 999;
      if ($ra !== $rb) return $ra <=> $rb;

      $ranka = (int)($a['rank'] ?? 999999);
      $rankb = (int)($b['rank'] ?? 999999);
      if ($ranka !== $rankb) return $ranka <=> $rankb;

      // tie breaker: score desc
      $sa = (float)($a['score'] ?? 0);
      $sb = (float)($b['score'] ?? 0);
      return $sb <=> $sa;
    });

  // group: level -> role
  $grouped = $rowsCol->groupBy([
    fn($r) => $r['level'] ?: 'UNKNOWN',
    fn($r) => $r['role']  ?: 'UNKNOWN',
  ]);

  $lvlBadgeCls = function(string $lvl) {
    return match($lvl) {
      'STAFF' => 'bg-slate-100 text-slate-700 border-slate-200',
      'TL'    => 'bg-indigo-100 text-indigo-800 border-indigo-200',
      'KASI'  => 'bg-fuchsia-100 text-fuchsia-800 border-fuchsia-200',
      'KABAG' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
      default => 'bg-slate-100 text-slate-700 border-slate-200',
    };
  };

  $modeBadgeCls = function(string $m) {
    $m = strtoupper($m);
    return match($m) {
      'REALTIME', 'LIVE' => 'bg-amber-50 text-amber-800 border-amber-200',
      'EOM', 'FREEZE'    => 'bg-slate-50 text-slate-700 border-slate-200',
      default            => 'bg-slate-50 text-slate-600 border-slate-200',
    };
  };

  // mode fallback (penting untuk BE karena tidak ada calc_mode)
  $resolveMode = function(array $r) {
    $m = strtoupper((string)($r['mode'] ?? ''));
    if ($m !== '') return $m;

    // fallback 1: jika ada status/approved_at (BE)
    $status = strtolower((string)($r['status'] ?? ''));
    if ($status === 'approved' || !empty($r['approved_at'])) return 'EOM';
    if (in_array($status, ['draft','submitted','pending'], true)) return 'REALTIME';

    // fallback 2: berdasarkan period bulan berjalan
    $p = (string)($r['period'] ?? '');
    $curYm = now()->format('Y-m');
    if (Str::startsWith($p, $curYm)) return 'REALTIME';

    return 'EOM';
  };

  $medal = function(int $rank) {
    return match(true) {
      $rank === 1 => '🥇',
      $rank === 2 => '🥈',
      $rank === 3 => '🥉',
      default => '',
    };
  };

  // semantic color untuk score (biar tidak semua merah)
  $scoreTone = function(float $score) {
    // asumsi skala 0..6
    if ($score >= 4.6) return ['text-emerald-700', 'bg-emerald-50', 'border-emerald-200'];
    if ($score >= 3.8) return ['text-indigo-700',  'bg-indigo-50',  'border-indigo-200'];
    if ($score >= 3.0) return ['text-amber-700',   'bg-amber-50',   'border-amber-200'];
    return ['text-rose-700', 'bg-rose-50', 'border-rose-200'];
  };

  // bar kecil biar hidup (skala 0..6)
  $scorePct = fn($score) => max(0, min(100, ((float)$score/6)*100));

  // ring khusus top 1-3
  $topRing = function(int $rank){
    return match(true) {
      $rank === 1 => 'ring-1 ring-amber-200 bg-gradient-to-b from-amber-50/70 to-white',
      $rank === 2 => 'ring-1 ring-slate-200 bg-gradient-to-b from-slate-50 to-white',
      $rank === 3 => 'ring-1 ring-orange-200 bg-gradient-to-b from-orange-50/60 to-white',
      default => 'border-slate-200 bg-white',
    };
  };
@endphp

<div class="max-w-7xl mx-auto p-4 sm:p-6 space-y-5">

  {{-- FILTER BAR (lebih compact) --}}
  <form method="GET" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
      <div class="sm:col-span-2">
        <label class="text-[11px] text-slate-500 font-extrabold tracking-wide">PERIOD</label>
        <input name="period" value="{{ $filters['period'] ?? '' }}"
          class="mt-1 w-full rounded-xl border-slate-200 text-sm focus:border-slate-300 focus:ring-0"
          placeholder="2026-02" />
      </div>

      <div class="sm:col-span-2">
        <label class="text-[11px] text-slate-500 font-extrabold tracking-wide">LEVEL</label>
        <select name="level" class="mt-1 w-full rounded-xl border-slate-200 text-sm focus:border-slate-300 focus:ring-0">
          @foreach(['ALL','STAFF','TL','KASI','KABAG'] as $opt)
            <option value="{{ $opt }}" @selected(($filters['level'] ?? 'ALL') === $opt)>{{ $opt }}</option>
          @endforeach
        </select>
      </div>

      <div class="sm:col-span-2">
        <label class="text-[11px] text-slate-500 font-extrabold tracking-wide">ROLE</label>
        <select name="role" class="mt-1 w-full rounded-xl border-slate-200 text-sm focus:border-slate-300 focus:ring-0">
          @foreach(['ALL','RO','SO','AO','FE','BE','TLRO','TLUM','TLFE','KSLR','KSBE','KSFE','KBL'] as $opt)
            <option value="{{ $opt }}" @selected(($filters['role'] ?? 'ALL') === $opt)>{{ $opt }}</option>
          @endforeach
        </select>
      </div>

      <div class="sm:col-span-4">
        <label class="text-[11px] text-slate-500 font-extrabold tracking-wide">SEARCH</label>
        <input name="q" value="{{ $filters['q'] ?? '' }}"
          class="mt-1 w-full rounded-xl border-slate-200 text-sm focus:border-slate-300 focus:ring-0"
          placeholder="nama / ao_code" />
      </div>

      <div class="sm:col-span-2 flex items-end gap-2">
        <button class="w-full rounded-xl bg-slate-900 text-white text-sm font-extrabold px-4 py-2 hover:bg-slate-800">
          Terapkan
        </button>
      </div>
    </div>
  </form>

  {{-- HEADER (lebih clean + action kanan) --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <div class="text-base font-extrabold text-slate-900">Ringkasan KPI</div>
        <div class="text-xs text-slate-500">Dikelompokkan per <span class="font-semibold text-slate-700">Level → Role</span>, urut <span class="font-semibold text-slate-700">Rank</span>.</div>
      </div>

      <div class="flex items-center gap-2">
        <div class="text-xs text-slate-500 mr-2">
          Total: <span class="font-extrabold text-slate-900">{{ $rowsCol->count() }}</span>
        </div>

        <a href="{{ route('kpi.export.all', ['period' => request('period'), 'q' => request('q')]) }}"
          class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-white text-sm font-extrabold hover:bg-emerald-700">
          <span>⬇️</span> Export
        </a>
      </div>
    </div>
  </div>

  {{-- CONTENT --}}
  @if($rowsCol->isEmpty())
    <div class="rounded-2xl border border-slate-200 bg-white p-10 text-center text-slate-500 shadow-sm">
      Data belum tersedia.
    </div>
  @else
    <div class="space-y-6">

      @foreach($grouped as $lvl => $byRole)
        @php $lvlCount = $byRole->flatten(1)->count(); @endphp

        <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
          {{-- Level header (lebih minimal, tidak rame) --}}
          <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-3">
              <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-extrabold border {{ $lvlBadgeCls($lvl) }}">
                {{ $lvl }}
              </span>
              <div class="text-sm font-extrabold text-slate-900">{{ $lvlCount }} orang</div>
            </div>
            <div class="hidden sm:block text-xs text-slate-500">Klik kartu untuk detail komponen & audit.</div>
          </div>

          <div class="p-5 space-y-7">
            @foreach($byRole as $role => $items)
              @php
                $items = collect($items)->values();
                $count = $items->count();
              @endphp

              <div class="space-y-3">
                {{-- Role header (simple) --}}
                <div class="flex items-end justify-between">
                  <div>
                    <div class="text-lg font-extrabold text-slate-900">{{ $role }}</div>
                    <div class="text-xs text-slate-500">{{ $count }} orang • Level {{ $lvl }}</div>
                  </div>
                </div>

                {{-- Cards grid (lebih lega, lebih stabil) --}}
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
                  @foreach($items as $idx => $r)
                    @php
                      $rank = (int)($r['rank'] ?? ($idx+1));
                      $m = $resolveMode($r);

                      $name = (string)($r['name'] ?? '-');
                      $unit = (string)($r['unit'] ?? '');
                      $score = (float)($r['score'] ?? 0);
                      $periodTxt = (string)($r['period'] ?? '-');
                      $detailUrl = $r['detail_url'] ?? null;

                      [$scoreText, $scoreBg, $scoreBorder] = $scoreTone($score);
                      $pct = $scorePct($score);

                      $cardCls = $rank <= 3 ? $topRing($rank) : 'border-slate-200 bg-white';
                    @endphp

                    <details class="group rounded-2xl border {{ $cardCls }} p-4 transition hover:shadow-md">
                      <summary class="cursor-pointer list-none">
                        <div class="flex items-start justify-between gap-3">
                          <div class="min-w-0">
                            <div class="flex items-center gap-2">
                              <div class="text-lg">{{ $medal($rank) }}</div>
                              <div class="text-xs font-extrabold text-slate-500">#{{ $rank }}</div>
                            </div>

                            <div class="mt-1 text-lg font-extrabold text-slate-900 leading-tight truncate">
                              {{ Str::limit($name, 26) }}
                            </div>

                            @if($unit !== '')
                              <div class="mt-0.5 text-[11px] text-slate-500 truncate">{{ $unit }}</div>
                            @endif
                          </div>

                          <div class="text-right shrink-0">
                            <div class="text-[11px] font-extrabold text-slate-500 tracking-wide">SCORE</div>
                            <div class="mt-1 inline-flex items-center justify-end rounded-xl px-3 py-1 border {{ $scoreBg }} {{ $scoreBorder }}">
                              <span class="text-2xl font-extrabold leading-none {{ $scoreText }}">{{ $fmt($score, 2) }}</span>
                            </div>
                          </div>
                        </div>

                        <div class="mt-4 flex items-center justify-between gap-2">
                          <div class="flex items-center gap-2 min-w-0">
                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-extrabold border {{ $modeBadgeCls($m) }}">
                              {{ $m }}
                            </span>
                            <div class="text-xs text-slate-600 truncate">{{ $periodTxt }}</div>
                          </div>

                          {{-- chevron indicator (CSS only) --}}
                          <div class="text-slate-400 group-open:rotate-180 transition select-none">
                            ▾
                          </div>
                        </div>

                        {{-- Progress bar (lebih halus) --}}
                        <div class="mt-3">
                          <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-2 rounded-full bg-slate-900" style="width: {{ $pct }}%"></div>
                          </div>
                          <div class="mt-1 flex items-center justify-between text-[11px] text-slate-500">
                            <span>Skala 0–6</span>
                            <span class="font-semibold text-slate-700">{{ $fmt($pct, 0) }}%</span>
                          </div>
                        </div>

                        {{-- Detail quick action (button kecil) --}}
                        <div class="mt-3 flex justify-end">
                          @if($detailUrl)
                            <a href="{{ $detailUrl }}"
                              class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-extrabold text-slate-700 hover:bg-slate-50">
                              Detail →
                            </a>
                          @endif
                        </div>
                      </summary>

                      {{-- DETAIL PANEL (lebih table-like biar cepat dibaca) --}}
                      <div class="mt-4">
                        @php
                          // ========= Helpers format =========
                          $fmtNum = fn($n, $d=2) => number_format((float)($n ?? 0), $d, ',', '.');
                          $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
                          $fmtPct = fn($n, $d=2) => $fmtNum($n, $d) . '%';

                          $fmtByKind = function($val, $kind='num') use ($fmtNum,$fmtRp,$fmtPct){
                              $kind = strtolower((string)$kind);
                              if ($kind === 'rp' || $kind === 'idr' || $kind === 'money') return $fmtRp($val);
                              if ($kind === 'pct' || $kind === 'percent') return $fmtPct($val);
                              if ($kind === 'int') return number_format((int)($val ?? 0), 0, ',', '.');
                              return $fmtNum($val, 2);
                          };

                          $componentsRaw = $r['detail']['components'] ?? [];
                          if (!is_array($componentsRaw)) $componentsRaw = [];

                          $labelFallbackByKey = [
                            'os' => 'Outstanding (OS)',
                            'noa' => 'NOA',
                            'rr' => 'Repayment Rate',
                            'dpk' => 'DPK',
                            'bunga' => 'Bunga Masuk',
                            'denda' => 'Denda Masuk',
                            'pi' => 'Total PI',
                            'kyd' => 'KYD',
                            'community' => 'Community',
                          ];

                          $components = [];

                          $isItems = isset($componentsRaw[0]) && is_array($componentsRaw[0]);
                          if ($isItems) {
                              foreach ($componentsRaw as $idx => $c) {
                                  if (!is_array($c)) continue;
                                  $label = trim((string)($c['label'] ?? ''));
                                  if ($label === '') $label = 'Komponen #' . ($idx+1);

                                  $components[] = [
                                      'label'  => $label,
                                      'val'    => $c['val'] ?? null,
                                      'target' => array_key_exists('target', $c) ? $c['target'] : null,
                                      'w'      => array_key_exists('w', $c) ? $c['w'] : null,
                                      'score'  => array_key_exists('score', $c) ? $c['score'] : null,
                                      'kind'   => $c['kind'] ?? ($c['fmt'] ?? 'num'),
                                      'note'   => $c['note'] ?? null,
                                  ];
                              }
                          } else {
                              foreach ($componentsRaw as $k => $v) {
                                  $key = strtolower((string)$k);
                                  $label = $labelFallbackByKey[$key] ?? strtoupper((string)$k);

                                  if (is_array($v)) {
                                      $components[] = [
                                          'label'  => $v['label'] ?? $label,
                                          'val'    => $v['val'] ?? ($v['value'] ?? null),
                                          'target' => $v['target'] ?? null,
                                          'w'      => $v['w'] ?? ($v['weight'] ?? null),
                                          'score'  => $v['score'] ?? null,
                                          'kind'   => $v['kind'] ?? 'num',
                                          'note'   => $v['note'] ?? null,
                                      ];
                                  } else {
                                      $components[] = [
                                          'label' => $label,
                                          'val'   => $v,
                                          'target'=> null,
                                          'w'     => null,
                                          'score' => null,
                                          'kind'  => 'num',
                                          'note'  => null,
                                      ];
                                  }
                              }
                          }
                        @endphp

                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                          <div class="flex items-center justify-between mb-3">
                            <div class="text-xs font-extrabold text-slate-900">Breakdown Komponen</div>
                            <div class="text-[11px] text-slate-500">{{ count($components) }} item</div>
                          </div>

                          @if(empty($components))
                            <div class="text-xs text-slate-500">Belum ada komponen detail.</div>
                          @else

                            {{-- =========================================
                                STACKED VIEW (DEFAULT) — always safe
                                NOTE: ini tampil di semua ukuran kecuali XL
                                supaya desktop sempit (karena sidebar) tetap rapi
                                ========================================= --}}
                            <div class="space-y-2 xl:hidden">
                              @foreach($components as $c)
                                @php
                                  $label  = $c['label'] ?? 'Komponen';
                                  $kind   = $c['kind'] ?? 'num';

                                  $val    = $c['val'] ?? null;
                                  $target = $c['target'] ?? null;
                                  $w      = $c['w'] ?? null;
                                  $cScore = $c['score'] ?? null;

                                  $showTarget = $target !== null && $target !== '';
                                  $showW      = $w !== null && $w !== '';
                                  $showScore  = $cScore !== null && $cScore !== '';

                                  $valTxt    = $fmtByKind($val, $kind);
                                  $targetTxt = $fmtByKind($target, $kind === 'pct' ? 'pct' : 'rp');
                                  $wTxt      = $fmtByKind($w, 'pct');
                                  $scoreTxt  = $fmtByKind($cScore, 'num');
                                @endphp

                                <div class="rounded-xl border border-slate-200 bg-white p-3">
                                  <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                      <div class="font-extrabold text-slate-900 truncate">{{ $label }}</div>
                                      @if(!empty($c['note']))
                                        <div class="mt-0.5 text-[11px] text-slate-500">{{ $c['note'] }}</div>
                                      @endif
                                    </div>

                                    <div class="shrink-0 flex items-center gap-2">
                                      @if($showW)
                                        <span class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-extrabold text-slate-700">
                                          W: {{ $wTxt }}
                                        </span>
                                      @endif
                                      @if($showScore)
                                        <span class="inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-extrabold text-slate-900">
                                          S: {{ $scoreTxt }}
                                        </span>
                                      @endif
                                    </div>
                                  </div>

                                  {{-- Value & Target dibuat 1 kolom biar ga sempit dan ga pecah --}}
                                  <div class="mt-2 space-y-2 text-xs">
                                    <div class="rounded-lg bg-slate-50 p-2">
                                      <div class="text-[11px] font-extrabold text-slate-500">VALUE</div>
                                      <div class="mt-0.5 font-semibold text-slate-900 whitespace-normal">
                                        {{ $valTxt }}
                                      </div>
                                    </div>

                                    <div class="rounded-lg bg-slate-50 p-2">
                                      <div class="text-[11px] font-extrabold text-slate-500">TARGET</div>
                                      <div class="mt-0.5 font-semibold text-slate-700 whitespace-normal">
                                        {{ $showTarget ? $targetTxt : '—' }}
                                      </div>
                                    </div>
                                  </div>
                                </div>
                              @endforeach
                            </div>


                            {{-- =========================================
                                TABLE VIEW — ONLY WHEN WIDE (XL+)
                                + overflow-x auto + min-width supaya ga pecah
                                + angka NO WRAP (whitespace-nowrap)
                                ========================================= --}}
                            <div class="hidden xl:block">
                              <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                                <div class="min-w-[720px]">
                                  <div class="grid grid-cols-12 gap-2 px-3 py-2 text-[11px] font-extrabold text-slate-500 bg-slate-50 border-b border-slate-200">
                                    <div class="col-span-5">Komponen</div>
                                    <div class="col-span-3 text-right">Value</div>
                                    <div class="col-span-2 text-right">Target</div>
                                    <div class="col-span-2 text-right">W / Score</div>
                                  </div>

                                  <div class="divide-y divide-slate-100">
                                    @foreach($components as $c)
                                      @php
                                        $label  = $c['label'] ?? 'Komponen';
                                        $kind   = $c['kind'] ?? 'num';

                                        $val    = $c['val'] ?? null;
                                        $target = $c['target'] ?? null;
                                        $w      = $c['w'] ?? null;
                                        $cScore = $c['score'] ?? null;

                                        $showTarget = $target !== null && $target !== '';
                                        $showW      = $w !== null && $w !== '';
                                        $showScore  = $cScore !== null && $cScore !== '';

                                        $valTxt    = $fmtByKind($val, $kind);
                                        $targetTxt = $fmtByKind($target, $kind === 'pct' ? 'pct' : 'rp');
                                        $wTxt      = $fmtByKind($w, 'pct');
                                        $scoreTxt  = $fmtByKind($cScore, 'num');
                                      @endphp

                                      <div class="px-3 py-2 text-xs">
                                        <div class="grid grid-cols-12 gap-2 items-start">
                                          <div class="col-span-5">
                                            <div class="font-bold text-slate-900">{{ $label }}</div>
                                            @if(!empty($c['note']))
                                              <div class="mt-0.5 text-[11px] text-slate-500">{{ $c['note'] }}</div>
                                            @endif
                                          </div>

                                          {{-- angka jangan break jadi vertikal --}}
                                          <div class="col-span-3 text-right text-slate-800 font-semibold whitespace-nowrap tabular-nums">
                                            {{ $valTxt }}
                                          </div>

                                          <div class="col-span-2 text-right text-slate-600 whitespace-nowrap tabular-nums">
                                            {{ $showTarget ? $targetTxt : '—' }}
                                          </div>

                                          <div class="col-span-2 text-right text-slate-700">
                                            <div class="text-[11px] whitespace-nowrap tabular-nums">
                                              <span class="font-semibold">W:</span> {{ $showW ? $wTxt : '—' }}
                                            </div>
                                            <div class="text-[11px] whitespace-nowrap tabular-nums">
                                              <span class="font-semibold">S:</span> {{ $showScore ? $scoreTxt : '—' }}
                                            </div>
                                          </div>
                                        </div>
                                      </div>
                                    @endforeach
                                  </div>

                                </div>
                              </div>
                            </div>

                          @endif
                        </div>

                        {{-- panel audit (biarkan commented, tapi kalau mau aktif sudah rapi) --}}
                        <!--
                        <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                          <div class="text-xs font-extrabold text-slate-900 mb-2">Audit & Source</div>
                          <div class="space-y-2">
                            @forelse(($r['detail']['audit'] ?? []) as $a)
                              <div class="rounded-xl border border-slate-200 bg-white p-3 text-xs">
                                <div class="font-bold text-slate-900">{{ $a['k'] ?? '-' }}</div>
                                <div class="text-slate-600">Source: {{ $a['src'] ?? '-' }}</div>
                                <div class="text-slate-600">Rule: {{ $a['rule'] ?? '-' }}</div>
                              </div>
                            @empty
                              <div class="text-xs text-slate-500">Audit belum tersedia.</div>
                            @endforelse
                          </div>
                        </div>
                        -->
                      </div>
                    </details>
                  @endforeach
                </div>
              </div>
            @endforeach
          </div>
        </section>
      @endforeach

    </div>
  @endif
</div>
@endsection