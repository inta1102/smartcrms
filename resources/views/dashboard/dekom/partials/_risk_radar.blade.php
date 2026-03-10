@php
    $riskRadar = $riskRadar ?? ['score' => 0, 'level' => 'watch', 'headline' => '-', 'items' => []];

    $level = $riskRadar['level'] ?? 'watch';
    $score = (int) ($riskRadar['score'] ?? 0);
    $headline = $riskRadar['headline'] ?? '-';
    $items = $riskRadar['items'] ?? [];

    $badgeClass = match ($level) {
        'critical' => 'bg-rose-50 text-rose-700 border-rose-200',
        'high'     => 'bg-amber-50 text-amber-700 border-amber-200',
        'medium'   => 'bg-yellow-50 text-yellow-700 border-yellow-200',
        default    => 'bg-slate-50 text-slate-700 border-slate-200',
    };
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-sm">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <div class="text-base font-bold text-slate-900">AI Risk Radar</div>
            <div class="mt-1 text-sm text-slate-500">
                Ringkasan prioritas risiko berdasarkan pergerakan kualitas, aging, restrukturisasi, dan pencapaian target.
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold {{ $badgeClass }}">
                    Level: {{ strtoupper($level) }}
                </span>
                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700">
                    Risk Score: {{ $score }}/100
                </span>
            </div>
        </div>

        <div class="w-full lg:w-64">
            <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 mb-2">Radar Meter</div>
            <div class="h-3 rounded-full bg-slate-100 overflow-hidden">
                <div
                    class="@if($level==='critical') bg-rose-500 @elseif($level==='high') bg-amber-500 @elseif($level==='medium') bg-yellow-500 @else bg-slate-400 @endif h-full rounded-full"
                    style="width: {{ min(max($score, 0), 100) }}%;"
                ></div>
            </div>
            <div class="mt-2 text-sm text-slate-700">{{ $headline }}</div>
        </div>
    </div>

    @if(empty($items))
        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            Belum ada sinyal risiko yang signifikan.
        </div>
    @else
        <div class="mt-4 grid grid-cols-1 xl:grid-cols-2 gap-3">
            @foreach($items as $item)
                @php
                    $severity = $item['severity'] ?? 'watch';

                    $itemClass = match ($severity) {
                        'critical' => 'border-rose-200 bg-rose-50',
                        'high'     => 'border-amber-200 bg-amber-50',
                        'medium'   => 'border-yellow-200 bg-yellow-50',
                        default    => 'border-slate-200 bg-slate-50',
                    };

                    $titleClass = match ($severity) {
                        'critical' => 'text-rose-800',
                        'high'     => 'text-amber-800',
                        'medium'   => 'text-yellow-800',
                        default    => 'text-slate-800',
                    };

                    $descClass = match ($severity) {
                        'critical' => 'text-rose-700',
                        'high'     => 'text-amber-700',
                        'medium'   => 'text-yellow-700',
                        default    => 'text-slate-600',
                    };
                @endphp

                <div class="rounded-xl border p-4 {{ $itemClass }}">
                    <div class="text-sm font-bold {{ $titleClass }}">
                        {{ $item['title'] ?? '-' }}
                    </div>
                    <div class="mt-1 text-sm leading-relaxed {{ $descClass }}">
                        {{ $item['desc'] ?? '-' }}
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>