@php
    $summary = $visitCoverageSummary ?? [];
    $rows    = $visitCoverageRows ?? collect();

    if (is_array($rows)) {
        $rows = collect($rows);
    }

    // =========================
    // Summary coverage portfolio aktif
    // =========================
    $totalNoa     = (int) ($summary['total_noa'] ?? 0);
    $doneVisit    = (int) ($summary['done_visit'] ?? 0);
    $planOnly     = (int) ($summary['plan_only'] ?? 0);
    $belumVisit   = (int) ($summary['belum_visit'] ?? 0);
    $coveragePct  = (float) ($summary['coverage_pct'] ?? 0);
    $osTotal      = (float) ($summary['os_total'] ?? 0);
    $osBelumVisit = (float) ($summary['os_belum_visit'] ?? 0);
    $ltNoa        = (int) ($summary['lt_noa'] ?? 0);
    $dpkNoa       = (int) ($summary['dpk_noa'] ?? 0);

    $badgeCoverage = $coveragePct >= 70
        ? 'text-emerald-700'
        : ($coveragePct >= 40 ? 'text-amber-700' : 'text-rose-700');

    $fmtInt = fn ($v) => number_format((int) $v, 0, ',', '.');
    $fmtPct = fn ($v) => number_format((float) $v, 2, ',', '.');
    $fmtCur = fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
@endphp

<div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    {{-- =======================
         HEADER
    ======================== --}}
    <div class="p-4 border-b border-slate-200">
        <div class="font-extrabold text-slate-900 text-lg">
            Coverage Kunjungan per RO
        </div>
        <div class="text-xs text-slate-500 mt-1">
            Fokus utama dashboard ini adalah progress coverage kunjungan terhadap seluruh NOA portfolio aktif per RO.
        </div>
        <div class="text-xs text-slate-500 mt-1">
            Coverage dibaca s/d <b>{{ $coverageTo ?? '-' }}</b>,
            portfolio berdasarkan snapshot <b>{{ $latestPosDate ?? '-' }}</b>.
        </div>
    </div>

    {{-- =======================
         A. SUMMARY UTAMA
    ======================== --}}
    <div class="p-4 border-b border-slate-200">
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">Total NOA Scope TL</div>
                <div class="text-2xl font-extrabold text-slate-900 mt-1">
                    {{ $fmtInt($totalNoa) }}
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">Sudah Divisit</div>
                <div class="text-2xl font-extrabold text-emerald-700 mt-1">
                    {{ $fmtInt($doneVisit) }}
                </div>
                <div class="text-[11px] text-slate-500 mt-1">
                    NOA portfolio aktif yang sudah tersentuh visit
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">Masih Plan</div>
                <div class="text-2xl font-extrabold text-sky-700 mt-1">
                    {{ $fmtInt($planOnly) }}
                </div>
                <div class="text-[11px] text-slate-500 mt-1">
                    Sudah direncanakan, belum tercatat done
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">Belum Divisit</div>
                <div class="text-2xl font-extrabold text-rose-700 mt-1">
                    {{ $fmtInt($belumVisit) }}
                </div>
                <div class="text-[11px] text-slate-500 mt-1">
                    NOA aktif yang belum tersentuh visit
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">Coverage Scope TL</div>
                <div class="text-2xl font-extrabold mt-1 {{ $badgeCoverage }}">
                    {{ $fmtPct($coveragePct) }}%
                </div>
                <div class="text-[11px] text-slate-500 mt-1">
                    Sudah divisit dibanding total NOA aktif
                </div>
            </div>
        </div>
    </div>

    {{-- =======================
         B. SUMMARY RISIKO / EKSPOSUR
    ======================== --}}
    <div class="p-4 border-b border-slate-200 bg-slate-50/40">
        <div class="mb-3">
            <div class="text-sm font-bold text-slate-900">Risk / Exposure Summary</div>
            <div class="text-xs text-slate-500">
                Gambaran risiko dan outstanding pada portfolio aktif yang menjadi scope tim.
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">LT Scope TL</div>
                <div class="text-2xl font-extrabold text-amber-700 mt-1">
                    {{ $fmtInt($ltNoa) }}
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">DPK Scope TL</div>
                <div class="text-2xl font-extrabold text-rose-700 mt-1">
                    {{ $fmtInt($dpkNoa) }}
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">OS Total Scope TL</div>
                <div class="text-2xl font-extrabold text-slate-900 mt-1">
                    {{ $fmtCur($osTotal) }}
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-4">
                <div class="text-xs text-slate-500">OS Belum Visit</div>
                <div class="text-2xl font-extrabold text-rose-700 mt-1">
                    {{ $fmtCur($osBelumVisit) }}
                </div>
            </div>
        </div>
    </div>

    {{-- =======================
         C. TABLE COVERAGE PER RO
    ======================== --}}
    <div class="p-4">
        <div class="mb-3">
            <div class="text-sm font-bold text-slate-900">Progress Coverage per RO</div>
            <div class="text-xs text-slate-500">
                Tabel ini menunjukkan dari total NOA kelolaan masing-masing RO, berapa yang sudah divisit, masih plan, dan belum tersentuh.
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50">
                    <tr class="text-slate-700">
                        <th class="text-left px-3 py-2 whitespace-nowrap">RO</th>
                        <th class="text-left px-3 py-2 whitespace-nowrap">AO</th>
                        <th class="text-right px-3 py-2 whitespace-nowrap">Total NOA</th>
                        <th class="text-right px-3 py-2 whitespace-nowrap">Sudah Divisit</th>
                        <th class="text-right px-3 py-2 whitespace-nowrap">Masih Plan</th>
                        <th class="text-right px-3 py-2 whitespace-nowrap">Belum Divisit</th>
                        <th class="text-right px-3 py-2 whitespace-nowrap">Coverage</th>
                        <th class="text-right px-3 py-2 whitespace-nowrap">LT</th>
                        <th class="text-right px-3 py-2 whitespace-nowrap">DPK</th>
                        <th class="text-right px-3 py-2 whitespace-nowrap">OS Total</th>
                        <th class="text-right px-3 py-2 whitespace-nowrap">OS Belum Visit</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-200">
                    @forelse($rows as $r)
                        @php
                            $rowCoverage = (float) ($r->coverage_pct ?? 0);

                            $rowCoverageClass = $rowCoverage >= 70
                                ? 'text-emerald-700'
                                : ($rowCoverage >= 40 ? 'text-amber-700' : 'text-rose-700');

                            $progressWidth = max(0, min(100, $rowCoverage));
                        @endphp

                        <tr class="hover:bg-slate-50/60 align-top">
                            <td class="px-3 py-3 font-semibold text-slate-900 whitespace-nowrap">
                                {{ $r->ro_name ?? '-' }}
                            </td>

                            <td class="px-3 py-3 font-mono whitespace-nowrap text-slate-700">
                                {{ $r->ao_code ?? '-' }}
                            </td>

                            <td class="px-3 py-3 text-right">
                                {{ $fmtInt($r->total_noa ?? 0) }}
                            </td>

                            <td class="px-3 py-3 text-right font-bold text-emerald-700">
                                {{ $fmtInt($r->done_visit ?? 0) }}
                            </td>

                            <td class="px-3 py-3 text-right font-bold text-sky-700">
                                {{ $fmtInt($r->plan_only ?? 0) }}
                            </td>

                            <td class="px-3 py-3 text-right font-bold text-rose-700">
                                {{ $fmtInt($r->belum_visit ?? 0) }}
                            </td>

                            <td class="px-3 py-3 text-right whitespace-nowrap">
                                <div class="flex items-end justify-end">
                                    <div class="min-w-[110px]">
                                        <div class="text-right font-bold {{ $rowCoverageClass }}">
                                            {{ $fmtPct($r->coverage_pct ?? 0) }}%
                                        </div>
                                        <div class="mt-1 h-2 rounded-full bg-slate-100 overflow-hidden">
                                            <div
                                                class="h-2 rounded-full {{ $rowCoverage >= 70 ? 'bg-emerald-500' : ($rowCoverage >= 40 ? 'bg-amber-500' : 'bg-rose-500') }}"
                                                style="width: {{ $progressWidth }}%;">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <td class="px-3 py-3 text-right text-amber-700 font-semibold">
                                {{ $fmtInt($r->lt_noa ?? 0) }}
                            </td>

                            <td class="px-3 py-3 text-right text-rose-700 font-semibold">
                                {{ $fmtInt($r->dpk_noa ?? 0) }}
                            </td>

                            <td class="px-3 py-3 text-right font-semibold whitespace-nowrap">
                                {{ $fmtCur($r->os_total ?? 0) }}
                            </td>

                            <td class="px-3 py-3 text-right font-semibold text-rose-700 whitespace-nowrap">
                                {{ $fmtCur($r->os_belum_visit ?? 0) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="px-3 py-8 text-center text-slate-500">
                                Belum ada data coverage kunjungan per RO pada periode ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if($rows->count())
                    <tfoot class="bg-slate-50 border-t border-slate-200">
                        <tr class="font-semibold text-slate-900">
                            <td class="px-3 py-3" colspan="2">TOTAL</td>
                            <td class="px-3 py-3 text-right">{{ $fmtInt($totalNoa) }}</td>
                            <td class="px-3 py-3 text-right text-emerald-700">{{ $fmtInt($doneVisit) }}</td>
                            <td class="px-3 py-3 text-right text-sky-700">{{ $fmtInt($planOnly) }}</td>
                            <td class="px-3 py-3 text-right text-rose-700">{{ $fmtInt($belumVisit) }}</td>
                            <td class="px-3 py-3 text-right {{ $badgeCoverage }}">{{ $fmtPct($coveragePct) }}%</td>
                            <td class="px-3 py-3 text-right text-amber-700">{{ $fmtInt($ltNoa) }}</td>
                            <td class="px-3 py-3 text-right text-rose-700">{{ $fmtInt($dpkNoa) }}</td>
                            <td class="px-3 py-3 text-right whitespace-nowrap">{{ $fmtCur($osTotal) }}</td>
                            <td class="px-3 py-3 text-right text-rose-700 whitespace-nowrap">{{ $fmtCur($osBelumVisit) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>