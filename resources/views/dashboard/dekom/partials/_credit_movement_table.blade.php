@php
    $title = $title ?? '-';
    $rows = collect($rows ?? []);
    $tone = $tone ?? 'slate';
    $showPlafondBaru = $showPlafondBaru ?? false;
    $grouped = $grouped ?? false;

    $toneClass = match ($tone) {
        'emerald' => 'border-emerald-200 bg-emerald-50/40',
        'rose'    => 'border-rose-200 bg-rose-50/40',
        default   => 'border-slate-200 bg-slate-50/40',
    };

    $fmtMoney = $fmtMoney ?? fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

    $order = [
        'ft0' => 1,
        'ft1' => 2,
        'ft2' => 3,
        'ft3' => 4,
        'kl'  => 5,
    ];

    $displayRows = $rows
        ->filter(function ($r) {
            return !in_array($r->line_key, ['total', 'grand_total', 'ft0_to_lunas'], true);
        })
        ->sortBy(function ($r) use ($order) {

            $parts = explode('_to_', strtolower($r->line_key));
            $from  = $parts[0] ?? '';

            return $order[$from] ?? 99;

        })
        ->values();

    $groupedRows = $grouped
        ? $displayRows->groupBy(fn ($r) => $r->subgroup ?: 'summary')
        : collect(['all' => $displayRows]);

    $subgroupTitle = function ($subgroup) {
        return match ($subgroup) {
            'ft1' => 'Bucket FT1',
            'ft2' => 'Bucket FT2',
            'ft3' => 'Bucket FT3',
            'flow' => 'Flow Pemburukan',
            'pelunasan' => 'Pelunasan',
            'pembukaan' => 'Pembukaan Kredit Baru',
            'summary' => 'Summary',
            default => strtoupper((string) $subgroup),
        };
    };
@endphp

<div class="rounded-2xl border {{ $toneClass }} overflow-hidden">
    <div class="px-4 py-3 border-b border-slate-200 bg-white">
        <div class="font-bold text-slate-900">{{ $title }}</div>
        <div class="text-xs text-slate-500 mt-1">
            Ditampilkan berdasarkan movement yang tersimpan di dashboard.
        </div>
    </div>

    <div class="p-4 space-y-4">
        @forelse($groupedRows as $subgroup => $items)
            @if($grouped && $subgroup !== 'all' && $items->count())
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    {{ $subgroupTitle($subgroup) }}
                </div>
            @endif

            @if($items->count())
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-white/70">
                            <tr class="text-slate-700">
                                <th class="px-3 py-2 text-left whitespace-nowrap">Uraian</th>
                                <th class="px-3 py-2 text-right whitespace-nowrap">NOA</th>
                                <th class="px-3 py-2 text-right whitespace-nowrap">O/S</th>
                                @if($showPlafondBaru)
                                    <th class="px-3 py-2 text-right whitespace-nowrap">Plafond Baru</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($items as $r)
                                <tr class="hover:bg-white/60">
                                    <td class="px-3 py-2 text-slate-900">{{ $r->line_label }}</td>
                                    <td class="px-3 py-2 text-right text-slate-700">{{ number_format((int) $r->noa_count, 0, ',', '.') }}</td>
                                    <td class="px-3 py-2 text-right text-slate-700">{{ $fmtMoney($r->os_amount) }}</td>
                                    @if($showPlafondBaru)
                                        <td class="px-3 py-2 text-right text-slate-700">
                                            {{ (float) $r->plafond_baru > 0 ? $fmtMoney($r->plafond_baru) : '-' }}
                                        </td>
                                    @endif
                                </tr>
                            @endforeach

                            @php
                                $subTotalNoa = $items->sum('noa_count');
                                $subTotalOs = $items->sum('os_amount');
                                $subTotalPlafond = $items->sum('plafond_baru');
                            @endphp

                            <tr class="bg-white font-bold">
                                <td class="px-3 py-2 text-slate-900">Total</td>
                                <td class="px-3 py-2 text-right text-slate-900">{{ number_format((int) $subTotalNoa, 0, ',', '.') }}</td>
                                <td class="px-3 py-2 text-right text-slate-900">{{ $fmtMoney($subTotalOs) }}</td>
                                @if($showPlafondBaru)
                                    <td class="px-3 py-2 text-right text-slate-900">
                                        {{ (float) $subTotalPlafond > 0 ? $fmtMoney($subTotalPlafond) : '-' }}
                                    </td>
                                @endif
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        @empty
            <div class="rounded-xl border border-dashed border-slate-300 px-4 py-4 text-sm text-slate-500">
                Belum ada data movement untuk section ini.
            </div>
        @endforelse
    </div>
</div>