@extends('layouts.app')

@section('content')
@php
    $cap = $cap ?? [];
    $filters = $filters ?? [];
    $range = $range ?? ['start' => null, 'end' => null];

    $snapshot = $snapshot ?? [];
    $trend = $trend ?? [];
    $distribution = $distribution ?? [];
    $attention = $attention ?? [];
    $leaderboard = $leaderboard ?? ['top' => [], 'bottom' => []];

    $roleLabel = $cap['is_direksi'] ?? false ? 'Direksi' : (($cap['is_komisaris'] ?? false) ? 'Komisaris' : 'Kabag');
    $debug = $debug ?? [];

@endphp

<div class="space-y-6">

    {{-- HEADER --}}
    <div class="rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm">
        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
            <div>
                <h1 class="text-base md:text-lg font-bold text-slate-900">
                    📊 Dashboard Executive
                    <span class="ml-2 inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-2.5 py-0.5 text-xs font-semibold text-slate-700">
                        {{ $roleLabel }}
                    </span>
                </h1>
                <p class="mt-1 text-sm text-slate-500">
                   @php
                        $periodStart = $range['start'] ?? null;
                        $periodEnd   = $range['end'] ?? null;
                    @endphp

                    <p class="mt-1 text-sm text-slate-500">
                        Periode:
                        <span class="font-medium text-slate-700">
                            {{ $periodStart ? \Carbon\Carbon::parse($periodStart)->format('d M Y') : '-' }}
                        </span>
                        s/d
                        <span class="font-medium text-slate-700">
                            {{ $periodEnd ? \Carbon\Carbon::parse($periodEnd)->format('d M Y') : '-' }}
                        </span>
                    </p>
                </p>
            </div>

            {{-- FILTER (minimal) --}}
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <select name="range" class="rounded-lg border-slate-200 text-sm">
                    @php $r = $filters['range'] ?? 'mtd'; @endphp
                    <option value="today"  @selected($r==='today')>Hari ini</option>
                    <option value="mtd"    @selected($r==='mtd')>Bulan berjalan</option>
                    <option value="ytd"    @selected($r==='ytd')>Tahun berjalan</option>
                    <option value="custom" @selected($r==='custom')>Custom</option>
                </select>

                <input type="date" name="start" value="{{ $filters['start'] ?? '' }}" class="rounded-lg border-slate-200 text-sm" />
                <input type="date" name="end" value="{{ $filters['end'] ?? '' }}" class="rounded-lg border-slate-200 text-sm" />

                @if(($cap['can_filter_granular'] ?? false))
                    <input type="text" name="ao_code" value="{{ $filters['ao_code'] ?? '' }}"
                        placeholder="AO Code (opsional)"
                        class="w-40 rounded-lg border-slate-200 text-sm" />
                @endif

                <button class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                    Terapkan
                </button>
            </form>
        </div>
    </div>

    {{-- SNAPSHOT CARDS --}}
    <!-- <div class="grid grid-cols-2 gap-3 md:grid-cols-6"> -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        @php
            $cards = [
                ['label'=>'Total Case', 'val'=>$snapshot['total_cases'] ?? 0],
                ['label'=>'Case Aktif', 'val'=>$snapshot['active_cases'] ?? 0],
                ['label'=>'Stagnan >30d', 'val'=>$snapshot['stagnant_30d'] ?? 0],
                ['label'=>'Overdue Follow-up', 'val'=>$snapshot['overdue_followups'] ?? 0],
                // ['label'=>'NPL Nominal', 'val'=>is_null($snapshot['npl_nominal'] ?? null) ? '-' : number_format((float)$snapshot['npl_nominal'], 0, ',', '.')],
                // ['label'=>'NPL Ratio', 'val'=>is_null($snapshot['npl_ratio'] ?? null) ? '-' : $snapshot['npl_ratio']],
            ];
        @endphp
        @foreach($cards as $c)
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="text-sm font-semibold text-slate-500">{{ $c['label'] }}</div>

                <div class="mt-2 flex items-baseline gap-2 min-w-0">
                    @if(($c['label'] ?? '') === 'NPL Nominal' && ($c['val'] ?? '-') !== '-')
                        <span class="text-2xl sm:text-3xl font-extrabold leading-none text-slate-900 shrink-0">Rp</span>

                        <span
                            class="text-2xl sm:text-3xl font-extrabold leading-none text-slate-900
                                min-w-0 flex-1 overflow-hidden text-ellipsis whitespace-nowrap"
                            title="Rp {{ $c['val'] }}"
                        >
                            {{ $c['val'] }}
                        </span>
                    @else
                        <span
                            class="text-2xl sm:text-3xl font-extrabold leading-none text-slate-900
                                max-w-full overflow-hidden text-ellipsis whitespace-nowrap"
                            title="{{ $c['val'] ?? '-' }}"
                        >
                            {{ $c['val'] ?? '-' }}
                        </span>
                    @endif
                </div>
            </div>
        @endforeach

        <!-- @foreach($cards as $c)
            <div class="rounded-2xl border border-slate-100 bg-white px-4 py-3 shadow-sm">
                <p class="text-xs text-slate-500">{{ $c['label'] }}</p>
                <p class="mt-1 text-lg font-bold text-slate-900">{{ $c['val'] }}</p>
            </div>
        @endforeach -->
    </div>

    {{-- ATTENTION LISTS --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- Overdue Followups --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold text-slate-900">⚠️ Overdue Follow-up</h2>
                <span class="text-xs text-slate-500">Top 5</span>
            </div>

            <div class="mt-3 space-y-2">
                @forelse(collect($attention['legal_cases'] ?? [])->take(5) as $it)
                    <div class="rounded-xl border border-rose-100 bg-rose-50 px-3 py-2">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold text-rose-800">
                                Case #{{ $it['case_id'] }} · AO {{ $it['ao_code'] }}
                            </p>
                            <span class="text-[11px] text-rose-700">
                                Due: {{ $it['next_due'] }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-rose-800/80">
                            {{ $it['action_type'] ?? '-' }}
                            · last: {{ $it['last_action_at'] ?? '-' }}
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Tidak ada overdue follow-up.</p>
                @endforelse
            </div>
        </div>

        {{-- Stagnant Cases --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold text-slate-900">🕒 Case Stagnan (&gt;30 hari)</h2>
                <span class="text-xs text-slate-500">Top 5</span>
            </div>

            <div class="mt-3 space-y-2">
                @forelse(collect($attention['stagnant_cases'])
                    ->sortBy(fn($i) => $i['last_action_at'] ?? '1900-01-01')
                    ->take(5);
                 as $it)
                    <div class="rounded-xl border border-amber-100 bg-amber-50 px-3 py-2">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold text-amber-900">
                                <span
                                    class="text-xs text-amber-800"
                                    title="Case tanpa aktivitas > 30 hari"
                                >
                                    Case #{{ $it['case_id'] }} · AO {{ $it['ao_code'] }}
                                </span>

                            </p>
                            <span class="text-[11px] text-amber-800">
                                Last: {{ $it['last_action_at'] ?? '-' }}
                            </span>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Tidak ada case stagnan.</p>
                @endforelse
            </div>
        </div>

        {{-- Legal Cases --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold text-slate-900">⚖️ Case Legal</h2>
                <span class="text-xs text-slate-500">Top 5</span>
            </div>

            <div class="mt-3 space-y-2">
                @forelse(collect($attention['legal_cases'] ?? [])->take(5) as $it)
                
                    <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-semibold text-slate-900">
                                Case #{{ $it['case_id'] }} · AO {{ $it['ao_code'] }}
                            </p>
                            <span class="text-[11px] text-slate-600">
                                {{ $it['updated_at'] ?? '-' }}
                            </span>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Tidak ada case legal.</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- DISTRIBUTION + LEADERBOARD + TREND (Minimal debug) --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

        {{-- Distribution --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-bold text-slate-900">📌 Distribusi Status Case</h2>

            <div class="mt-3 space-y-2">
                @forelse($distribution as $d)
                    <div class="flex items-center justify-between rounded-xl border border-slate-100 px-3 py-2">
                        <span class="text-sm text-slate-700">{{ $d['status'] }}</span>
                        <span class="text-sm font-semibold text-slate-900">{{ $d['total'] }}</span>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Belum ada distribusi status (cek kolom status).</p>
                @endforelse
            </div>
        </div>

        {{-- Leaderboard --}}
        @php
            $mode = $picKpi['mode'] ?? 'completion';

            // Field yang ditampilkan untuk tiap mode
            $label = match($mode) {
                'risk'       => 'Risk',
                'ontime'     => 'Ontime',
                default      => 'Completion',
            };

            // Ambil nilai persen yang benar sesuai mode
            $percent = function(array $row) use ($mode) {
                return match($mode) {
                    'risk'   => $row['risk_score'] ?? ($row['score'] ?? 0),      // kalau kamu pakai risk_score
                    'ontime' => $row['ontime_rate'] ?? ($row['score'] ?? 0),     // kalau kamu pakai ontime_rate
                    default  => $row['rate'] ?? ($row['score'] ?? 0),            // completion pakai rate
                };
            };

            // Format angka
            $fmt = fn($v) => number_format((float)$v, 1);

            // Auto fallback: kalau mode completion tapi semua rate = 0, tampilkan hint
            $allZeroCompletion = $mode === 'completion'
                && !empty($picKpi['top'])
                && collect($picKpi['top'])->every(fn($r) => (float)($r['rate'] ?? 0) <= 0);
        @endphp

        <div class="rounded-xl border p-4 bg-white">
            <div class="flex items-center justify-between">
                <h3 class="font-semibold text-slate-800">
                    🏆 Leaderboard PIC (Kinerja)
                    <span class="text-xs text-slate-500">Mode: {{ $label }}</span>
                </h3>

                {{-- optional: badge info --}}
                @if($allZeroCompletion)
                    <span class="text-xs text-amber-700 bg-amber-50 border border-amber-200 px-2 py-1 rounded-lg">
                        Done masih 0 → completion 0%
                    </span>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-4 mt-4">
                {{-- TOP --}}
                <div>
                    <div class="text-xs font-semibold text-slate-500 mb-2">TOP 5</div>

                    @forelse(($picKpi['top'] ?? []) as $row)
                        <div class="flex justify-between py-1">
                            <div class="min-w-0">
                                <div class="font-medium text-slate-800 truncate">
                                    {{ $row['name'] ?? $row['pic_name'] ?? ('User#'.($row['user_id'] ?? $row['pic_user_id'] ?? '-')) }}
                                </div>

                                {{-- optional: detail kecil biar informatif --}}
                                @if(isset($row['done'], $row['total']))
                                    <div class="text-xs text-slate-500">
                                        Done {{ $row['done'] }} / {{ $row['total'] }}
                                    </div>
                                @endif
                            </div>

                            <div class="font-bold text-slate-900">
                                {{ $fmt($percent($row)) }}%
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-400">Belum ada data.</div>
                    @endforelse
                </div>

                {{-- BOTTOM --}}
                <div>
                    <div class="text-xs font-semibold text-slate-500 mb-2">BOTTOM 5</div>

                    @forelse(($picKpi['bottom'] ?? []) as $row)
                        <div class="flex justify-between py-1">
                            <div class="min-w-0">
                                <div class="font-medium text-slate-800 truncate">
                                    {{ $row['name'] ?? $row['pic_name'] ?? ('User#'.($row['user_id'] ?? $row['pic_user_id'] ?? '-')) }}
                                </div>

                                @if(isset($row['done'], $row['total']))
                                    <div class="text-xs text-slate-500">
                                        Done {{ $row['done'] }} / {{ $row['total'] }}
                                    </div>
                                @endif
                            </div>

                            <div class="font-bold text-slate-900">
                                {{ $fmt($percent($row)) }}%
                            </div>
                        </div>
                    @empty
                        <div class="text-sm text-slate-400">Belum ada data.</div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Trend (debug list dulu) --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <h2 class="text-sm font-bold text-slate-900">📈 Trend (Debug)</h2>
            <p class="mt-1 text-xs text-slate-500">Sementara list dulu. Nanti kita ganti chart.</p>

            <div class="mt-3 space-y-2">
                @forelse($trend as $t)
                    <div class="flex items-center justify-between rounded-xl border border-slate-100 px-3 py-2">
                        <span class="text-sm text-slate-700">{{ $t['ym'] }}</span>
                        <span class="text-sm font-semibold text-slate-900">{{ $t['accounts'] }}</span>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">Trend kosong (cek position_date).</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- RAW DEBUG (opsional, boleh hapus kalau sudah stabil) --}}
    <details class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
        <summary class="cursor-pointer text-sm font-semibold text-slate-900">🧪 Debug Payload (klik)</summary>
        <pre class="mt-3 overflow-auto rounded-xl bg-slate-900 p-3 text-xs text-slate-100">{{ json_encode(compact('cap','filters','range','snapshot','attention','distribution','leaderboard','trend','debug'), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
    </details>

</div>
@endsection
