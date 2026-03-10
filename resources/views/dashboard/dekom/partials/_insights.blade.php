<div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-sm">
    <div class="flex items-start justify-between gap-3">
        <div>
            <div class="text-base font-bold text-slate-900">Insight Komisaris</div>
            <div class="mt-1 text-sm text-slate-500">
                Highlight utama kondisi portofolio dan pencapaian periode berjalan.
            </div>
        </div>
    </div>

    @if(empty($insights))
        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            Belum ada insight yang dapat ditampilkan.
        </div>
    @else
        <div class="mt-4 grid grid-cols-1 lg:grid-cols-2 gap-3">
            @foreach($insights as $item)
                @php
                    $type = $item['type'] ?? 'neutral';

                    $classes = match ($type) {
                        'positive' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
                        'warning'  => 'border-amber-200 bg-amber-50 text-amber-800',
                        'danger'   => 'border-rose-200 bg-rose-50 text-rose-800',
                        default    => 'border-slate-200 bg-slate-50 text-slate-700',
                    };
                @endphp

                <div class="rounded-xl border px-4 py-3 text-sm leading-relaxed {{ $classes }}">
                    {{ $item['text'] ?? '-' }}
                </div>
            @endforeach
        </div>
    @endif
</div>