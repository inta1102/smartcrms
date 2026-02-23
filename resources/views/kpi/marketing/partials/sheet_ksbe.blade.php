@php
  $fmtRp  = fn($n) => 'Rp ' . number_format((float)($n ?? 0), 0, ',', '.');
  $fmtPct = fn($n, $d=2) => number_format((float)($n ?? 0), $d) . '%';

  $leaderName  = data_get($leader, 'name', '-');
  $leaderLevel = data_get($leader, 'level', 'KSBE');

  $scopeCnt = is_countable($items ?? null) ? count($items) : 0;

  // --- leadership payload (silakan sesuaikan keys kamu)
  $LI       = (float) data_get($leadership, 'li', 0);          // mis: 2.00
  $PI_scope = (float) data_get($leadership, 'pi_scope', 0);    // mis: 1.50
  $SI       = (float) data_get($leadership, 'stability', 0);   // mis: 2.10
  $Risk     = (float) data_get($leadership, 'risk', 0);        // mis: 3
  $Improve  = (float) data_get($leadership, 'improve', 0);     // mis: 3

  $riskHint = (string) data_get($leadership, 'risk_hint', '');       // "NPL drop 0.59%"
  $impHint  = (string) data_get($leadership, 'improve_hint', '');    // "MoM belum ada"
  $siHint   = (string) data_get($leadership, 'stability_hint', '');  // "Coverage • Spread • Bottom"
  $piHint   = (string) data_get($leadership, 'pi_scope_hint', 'Target/Actual agregat');

  // recap (agregat KPI BE)
  $rT = (array) data_get($recap, 'target', []);
  $rA = (array) data_get($recap, 'actual', []);
  $rAch = (array) data_get($recap, 'ach', []);
@endphp

<!-- <div class="space-y-6">

  {{-- =========================
       COCKPIT TOP
       ========================= --}}
  <div class="rounded-3xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-5 sm:p-6">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">

        {{-- LEFT: identity --}}
        <div class="lg:col-span-4">
          <div class="flex items-start justify-between gap-3">
            <div>
              <div class="text-2xl font-black text-slate-900 leading-tight">KPI KSBE – Sheet</div>
              <div class="text-sm text-slate-500 mt-1">
                Periode: <b>{{ $period->translatedFormat('M Y') }}</b>
              </div>
            </div>

            <div class="shrink-0">
              <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 text-amber-800 px-3 py-1 text-xs font-bold">
                Leadership Index: {{ number_format($LI, 2) }}
              </span>
            </div>
          </div>

          <div class="mt-4 space-y-2 text-sm">
            <div class="text-slate-700">
              Leader: <b class="text-slate-900">{{ $leaderName }}</b>
            </div>
            <div class="text-slate-700">
              Role: <b class="text-slate-900">{{ strtoupper((string)$leaderLevel) }}</b>
            </div>

            <div class="flex flex-wrap gap-2 pt-2">
              <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">
                Scope: {{ $scopeCnt }} BE
              </span>
              <span class="inline-flex items-center rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-semibold text-indigo-700">
                LI = 55% PI_scope + 20% Stability + 15% Risk + 10% Improve
              </span>
            </div>

            <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
              <b>PI_scope</b> = agregat pencapaian KPI BE (OS/NOA/Bunga/Denda) pada scope.
            </div>
          </div>
        </div>

        {{-- RIGHT: 4 cards --}}
        <div class="lg:col-span-8">
          <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
              <div class="text-xs text-slate-500">PI_scope</div>
              <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ number_format($PI_scope, 2) }}</div>
              <div class="text-xs text-slate-500 mt-1">{{ $piHint ?: 'Target/Actual agregat' }}</div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
              <div class="text-xs text-slate-500">Stability</div>
              <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ number_format($SI, 2) }}</div>
              <div class="text-xs text-slate-500 mt-1">{{ $siHint ?: 'Coverage • Spread • Bottom' }}</div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
              <div class="text-xs text-slate-500">Risk</div>
              <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ number_format($Risk, 0) }}</div>
              <div class="text-xs text-slate-500 mt-1">{{ $riskHint ?: 'NPL drop' }}</div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
              <div class="text-xs text-slate-500">Improve</div>
              <div class="text-2xl font-extrabold text-slate-900 mt-1">{{ number_format($Improve, 0) }}</div>
              <div class="text-xs text-slate-500 mt-1">{{ $impHint ?: 'MoM belum ada' }}</div>
            </div>

          </div>
        </div>

      </div>
    </div>

    {{-- divider --}}
    <div class="border-t border-slate-200"></div> -->

    <!-- {{-- =========================
         SECOND ROW PANELS
         ========================= --}}
    <div class="p-5 sm:p-6">
      <div class="grid grid-cols-1 lg:grid-cols-12 gap-4">

        {{-- Summary --}}
        <div class="lg:col-span-4 rounded-2xl border border-slate-200 bg-white p-4">
          <div class="flex items-center justify-between">
            <div class="text-sm font-extrabold text-slate-900">Leadership Summary (KSBE)</div>
            <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">
              Coverage {{ number_format((float)data_get($leadership,'coverage_pct',0), 2) }}%
            </span>
          </div>

          <div class="mt-4 grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs text-slate-500">OS Recovery</div>
              <div class="text-lg font-extrabold text-slate-900 mt-1">{{ $fmtRp($rA['os'] ?? 0) }}</div>
              <div class="text-xs text-slate-500 mt-1">Target {{ $fmtRp($rT['os'] ?? 0) }}</div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs text-slate-500">NOA Selesai</div>
              <div class="text-lg font-extrabold text-slate-900 mt-1">{{ number_format((int)($rA['noa'] ?? 0)) }}</div>
              <div class="text-xs text-slate-500 mt-1">Target {{ number_format((int)($rT['noa'] ?? 0)) }}</div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs text-slate-500">Bunga Masuk</div>
              <div class="text-lg font-extrabold text-slate-900 mt-1">{{ $fmtRp($rA['bunga'] ?? 0) }}</div>
              <div class="text-xs text-slate-500 mt-1">Target {{ $fmtRp($rT['bunga'] ?? 0) }}</div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs text-slate-500">Denda Masuk</div>
              <div class="text-lg font-extrabold text-slate-900 mt-1">{{ $fmtRp($rA['denda'] ?? 0) }}</div>
              <div class="text-xs text-slate-500 mt-1">Target {{ $fmtRp($rT['denda'] ?? 0) }}</div>
            </div>
          </div>

          <div class="mt-3 text-xs text-slate-500">
            Ach OS {{ $fmtPct($rAch['os'] ?? 0) }} •
            Ach NOA {{ $fmtPct($rAch['noa'] ?? 0) }} •
            Ach Bunga {{ $fmtPct($rAch['bunga'] ?? 0) }} •
            Ach Denda {{ $fmtPct($rAch['denda'] ?? 0) }}
          </div>
        </div>

        {{-- Stability --}}
        <div class="lg:col-span-4 rounded-2xl border border-slate-200 bg-white p-4">
          <div class="flex items-center justify-between">
            <div class="text-sm font-extrabold text-slate-900">Stability Index (SI)</div>
            <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 text-amber-800 px-3 py-1 text-xs font-bold">
              SI {{ number_format($SI, 2) }}
            </span>
          </div>

          <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs text-slate-500">Coverage</div>
              <div class="text-lg font-extrabold text-slate-900 mt-1">
                {{ $fmtPct((float)data_get($leadership,'coverage_pct',0), 2) }}
              </div>
              <div class="text-xs text-slate-500 mt-1">BE aktif / scope</div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs text-slate-500">Spread (StdDev)</div>
              <div class="text-lg font-extrabold text-slate-900 mt-1">
                {{ number_format((float)data_get($leadership,'spread_stddev',0), 2) }}
              </div>
              <div class="text-xs text-slate-500 mt-1">Penyebaran PI staff</div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 sm:col-span-2">
              <div class="text-xs text-slate-500">Bottom concentration</div>
              <div class="text-lg font-extrabold text-slate-900 mt-1">
                {{ $fmtPct((float)data_get($leadership,'bottom_concentration_pct',0), 2) }}
              </div>
              <div class="text-xs text-slate-500 mt-1">Kontribusi bottom terhadap gap</div>
            </div>
          </div>
        </div>

        {{-- Controls --}}
        <div class="lg:col-span-4 rounded-2xl border border-slate-200 bg-white p-4">
          <div class="flex items-center justify-between">
            <div class="text-sm font-extrabold text-slate-900">Leadership Controls</div>
            <span class="inline-flex items-center rounded-full border border-amber-200 bg-amber-50 text-amber-800 px-3 py-1 text-xs font-bold">
              LI {{ number_format($LI, 2) }}
            </span>
          </div>

          <div class="mt-4 grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs text-slate-500">Risk Index</div>
              <div class="text-lg font-extrabold text-slate-900 mt-1">{{ number_format($Risk, 0) }}</div>
              <div class="text-xs text-slate-500 mt-1">{{ $riskHint }}</div>
            </div>

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <div class="text-xs text-slate-500">Improvement</div>
              <div class="text-lg font-extrabold text-slate-900 mt-1">{{ number_format($Improve, 0) }}</div>
              <div class="text-xs text-slate-500 mt-1">{{ $impHint }}</div>
            </div>
          </div>

          <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600">
            <b>Focus coaching:</b> {{ (string)data_get($leadership,'focus_coaching','OS Recovery') }}
          </div>
        </div>

      </div>
    </div>
  </div> -->

    @php
    $ai = $ksbeAi ?? null;
    if ($ai) {
        $tone = $ai['status']['tone'] ?? 'info';

        $toneMap = [
        'success' => 'bg-emerald-50 border-emerald-200 text-emerald-900',
        'info'    => 'bg-sky-50 border-sky-200 text-sky-900',
        'warning' => 'bg-amber-50 border-amber-200 text-amber-900',
        'danger'  => 'bg-rose-50 border-rose-200 text-rose-900',
        ];
        $panelCls = $toneMap[$tone] ?? $toneMap['info'];

        $badgeLevel = function ($level) {
        $level = strtolower((string)($level ?? 'low'));
        return match ($level) {
            'good' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
            'warn' => 'bg-amber-100 text-amber-800 border-amber-200',
            'na'   => 'bg-slate-100 text-slate-700 border-slate-200',
            default=> 'bg-rose-100 text-rose-800 border-rose-200',
        };
        };

        $levelText = function ($level) {
        $level = strtolower((string)($level ?? 'low'));
        return match ($level) {
            'good' => 'GOOD',
            'warn' => 'WARN',
            'na'   => 'NA',
            default=> 'LOW',
        };
        };

        $signals = $ai['signals'] ?? [];
        $sigOrder = ['pi_scope','stability','risk','improve'];
    }
    @endphp

    @if($ai)
    <div class="rounded-3xl border p-5 {{ $panelCls }}">

        {{-- HEADER --}}
        <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
        <div>
            <div class="text-sm font-extrabold">🤖 AI Leadership Engine</div>
            <div class="text-lg font-black mt-1">{{ $ai['summary']['headline'] ?? '-' }}</div>

            @if(!empty($ai['summary']['weak_note']))
            <div class="text-xs opacity-80 mt-1">{{ $ai['summary']['weak_note'] }}</div>
            @endif
        </div>

        <div class="md:text-right">
            <div class="text-xs opacity-70">Status</div>
            <div class="inline-flex items-center gap-2 mt-1">
            <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-extrabold {{ $badgeLevel($tone==='success'?'good':($tone==='warning'?'warn':($tone==='danger'?'low':'warn'))) }}">
                {{ $ai['status']['label'] ?? '-' }}
            </span>
            <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-extrabold bg-white/60 border-white/50">
                LI {{ number_format((float)($ai['status']['score'] ?? 0), 2) }}
            </span>
            </div>
        </div>
        </div>

        {{-- SIGNAL CARDS (lebih kebaca daripada list) --}}
        <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach($sigOrder as $k)
            @php
            $s = $signals[$k] ?? null;
            $nm = $s['name'] ?? strtoupper($k);
            $val = number_format((float)($s['value'] ?? 0), 2);
            $lvl = $s['level'] ?? 'low';
            $why = $s['why'] ?? '';
            @endphp

            <div class="rounded-2xl bg-white/60 border border-white/50 p-4">
            <div class="flex items-start justify-between gap-2">
                <div class="text-xs font-bold opacity-70">{{ $nm }}</div>
                <span class="inline-flex items-center px-2.5 py-1 rounded-full border text-[11px] font-extrabold {{ $badgeLevel($lvl) }}">
                {{ $levelText($lvl) }}
                </span>
            </div>

            <div class="text-2xl font-black mt-1">{{ $val }}</div>
            <div class="text-xs opacity-75 mt-1">{{ $why }}</div>
            </div>
        @endforeach
        </div>

        {{-- MAKNA + KENAPA + ACTION NOW --}}
        <div class="mt-4 grid grid-cols-1 lg:grid-cols-3 gap-3">

        {{-- MAKNA --}}
        <div class="rounded-2xl bg-white/60 border border-white/50 p-4">
            <div class="text-xs font-bold opacity-70">Makna (untuk KSBE)</div>
            <div class="mt-2 text-sm font-semibold leading-relaxed">
            {!! nl2br(e($ai['summary']['meaning'] ?? '-')) !!}
            </div>
        </div>

        {{-- KENAPA --}}
        <div class="rounded-2xl bg-white/60 border border-white/50 p-4">
            <div class="text-xs font-bold opacity-70">Kenapa (indikator utama)</div>
            <ul class="mt-2 space-y-1 text-sm">
            @foreach(($ai['summary']['why'] ?? []) as $w)
                <li>• {{ $w }}</li>
            @endforeach
            @if(empty($ai['summary']['why']))
                <li class="opacity-80">• -</li>
            @endif
            </ul>
        </div>

        {{-- ACTIONS NOW --}}
        <div class="rounded-2xl bg-white/60 border border-white/50 p-4">
            <div class="text-xs font-bold opacity-70">Actions Now (langsung eksekusi)</div>
            <ul class="mt-2 space-y-1 text-sm">
            @foreach(($ai['summary']['actions_now'] ?? []) as $x)
                <li>• {{ $x }}</li>
            @endforeach
            @if(empty($ai['summary']['actions_now']))
                <li class="opacity-80">• -</li>
            @endif
            </ul>
        </div>

        </div>

        {{-- PRIORITIES + COACHING --}}
        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3">

        <div class="rounded-2xl bg-white/60 border border-white/50 p-4">
            <div class="text-xs font-bold opacity-70">Priority Focus (3)</div>
            <ul class="mt-2 space-y-1 text-sm">
            @foreach(($ai['priorities'] ?? []) as $p)
                <li>• <b>{{ $p['title'] }}</b> — {{ $p['reason'] }}</li>
            @endforeach
            @if(empty($ai['priorities']))
                <li class="opacity-80">• -</li>
            @endif
            </ul>

            @if(!empty($ai['summary']['focus']))
            <div class="mt-3 text-xs opacity-80">
                @foreach(($ai['summary']['focus'] ?? []) as $f)
                <div>• {{ $f }}</div>
                @endforeach
            </div>
            @endif
        </div>

        <div class="rounded-2xl bg-white/60 border border-white/50 p-4">
            <div class="text-xs font-bold opacity-70">Coaching Targets</div>

            <div class="mt-2 text-sm">
            <div class="font-extrabold">Top 3</div>
            <div class="opacity-85">
                @foreach(($ai['coaching']['top3'] ?? []) as $x)
                <div>• {{ $x['name'] }} ({{ $x['code'] }}) — {{ number_format((float)$x['pi'],2) }}</div>
                @endforeach
                @if(empty($ai['coaching']['top3']))
                <div>• -</div>
                @endif
            </div>

            <div class="font-extrabold mt-3">Bottom 3</div>
            <div class="opacity-85">
                @foreach(($ai['coaching']['bottom3'] ?? []) as $x)
                <div>• {{ $x['name'] }} ({{ $x['code'] }}) — {{ number_format((float)$x['pi'],2) }}</div>
                @endforeach
                @if(empty($ai['coaching']['bottom3']))
                <div>• -</div>
                @endif
            </div>
            </div>
        </div>

        </div>

        {{-- RECOMMENDED ACTIONS (PLAYBOOK) --}}
        <div class="mt-4 rounded-2xl bg-white/60 border border-white/50 p-4">
        <div class="text-xs font-bold opacity-70">Recommended Actions (Playbook)</div>
        <div class="mt-3 space-y-3">
            @foreach(($ai['actions'] ?? []) as $a)
            <div class="rounded-xl bg-white/70 border border-white/60 p-3">
                <div class="font-extrabold">{{ $a['title'] ?? '-' }}</div>
                <div class="text-xs opacity-80 mt-1">
                Owner: {{ $a['who'] ?? '-' }} • Metric: {{ $a['metric'] ?? '-' }}
                </div>
                <ul class="list-disc pl-5 mt-2 text-sm">
                @foreach(($a['steps'] ?? []) as $s)
                    <li>{{ $s }}</li>
                @endforeach
                @if(empty($a['steps']))
                    <li>-</li>
                @endif
                </ul>
            </div>
            @endforeach

            @if(empty($ai['actions']))
            <div class="text-sm opacity-80">Belum ada rekomendasi aksi.</div>
            @endif
        </div>
        </div>

        {{-- FOOTER NOTE (DINAMIS) --}}
        @if(!empty($ai['summary']['meaning']))
        <div class="mt-4 border-l-4 border-white/70 pl-4 text-sm font-semibold">
            {{ $ai['summary']['meaning'] }}
        </div>
        @endif

    </div>
    @endif

     {{-- divider --}}
    <div class="border-t border-slate-200"></div>

    <!-- @php
        $interpretations = [];

        if ($PI_scope <= 2) {
            $interpretations[] = "Secara agregat, performa tim BE masih lemah.";
        }

        if ($Improve <= 1) {
            $interpretations[] = "Belum ada improvement signifikan dibanding bulan sebelumnya.";
        }

        if ($SI <= 2) {
            $interpretations[] = "Stabilitas tim rendah, distribusi performa belum sehat.";
        }

        if ($Risk <= 2) {
            $interpretations[] = "Kontrol risiko dan dampak terhadap NPL masih lemah.";
        }

        $leadershipConclusion = "Leadership effectiveness kamu sebagai KSBE belum terbentuk secara sistemik.";

        
    @endphp

    <div class="rounded-3xl border border-slate-200 bg-amber-50 p-5">
        <div class="text-sm font-extrabold text-slate-900 mb-3">
            🧠 Leadership Interpretation
        </div>

        <ul class="list-disc pl-5 space-y-1 text-sm text-slate-700">
            @foreach($interpretations as $msg)
                <li>{{ $msg }}</li>
            @endforeach
        </ul>

        <div class="mt-4 border-l-4 border-amber-400 pl-4 text-sm font-semibold text-slate-900">
            {{ $leadershipConclusion }}
        </div>
    </div> -->
  {{-- =========================
       LIST BE (reuse sheet_be)
       ========================= --}}
  <div>
    @include('kpi.marketing.partials.sheet_be')
  </div>

</div>