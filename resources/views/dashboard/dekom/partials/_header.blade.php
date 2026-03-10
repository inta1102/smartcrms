<div class="rounded-3xl border border-slate-200 bg-white p-4 sm:p-5 lg:p-6 shadow-sm">
    <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
        <div class="min-w-0">
            <div class="text-l sm:text-xl font-bold tracking-tight text-slate-900">
                Dashboard Kondisi Kredit
            </div>

            <div class="mt-2 text-sm sm:text-base text-slate-500">
                Ringkasan posisi kredit, kualitas portofolio, realisasi, dan tren bulanan.
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <span class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-medium {{ $nplBadge }}">
                    NPL {{ number_format((float) $nplPct, 2, ',', '.') }}%
                </span>

                <span class="inline-flex items-center rounded-full border px-3 py-1.5 text-xs font-medium {{ $achBadge }}">
                    Ach OS {{ number_format((float) $achOsPct, 2, ',', '.') }}%
                </span>

                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700">
                    Source: {{ $portfolioSource }}
                </span>

                <span class="inline-flex items-center rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700">
                    As Of:
                    {{ $safeRow?->as_of_date ? \Carbon\Carbon::parse($safeRow->as_of_date)->format('d-m-Y') : '-' }}
                </span>
            </div>
        </div>

        <form method="GET" action="{{ route('dashboard.dekom.index') }}"
              class="grid grid-cols-1 sm:grid-cols-3 gap-3 w-full xl:w-auto xl:min-w-[520px]">
            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1.5">
                    Periode
                </label>
                <select name="period"
                        class="w-full rounded-2xl border-slate-300 text-sm font-medium focus:border-slate-400 focus:ring-slate-400">
                    @forelse($availablePeriods as $p)
                        <option value="{{ $p }}" @selected($p === $period)>{{ $p }}</option>
                    @empty
                        <option value="{{ $period }}">{{ $period }}</option>
                    @endforelse
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold uppercase tracking-wide text-slate-500 mb-1.5">
                    Mode
                </label>
                <select name="mode"
                        class="w-full rounded-2xl border-slate-300 text-sm font-medium focus:border-slate-400 focus:ring-slate-400">
                    <option value="eom" @selected($mode==='eom')>EOM</option>
                    <option value="realtime" @selected($mode==='realtime')>Realtime</option>
                    <option value="hybrid" @selected($mode==='hybrid')>Hybrid</option>
                </select>
            </div>

            <div class="flex items-end">
                <button type="submit"
                        class="w-full rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800">
                    Tampilkan
                </button>
            </div>
        </form>
    </div>
</div>