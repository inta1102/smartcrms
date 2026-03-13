@php
    $waterfall = $waterfall ?? [
        'os_prev' => 0,
        'disbursement' => 0,
        'pelunasan' => 0,
        'os_current' => 0,
        'delta' => 0,
        'recon_delta' => 0,
        'is_live' => false,
    ];

    $fmtMoney = fn($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');
    $fmtPct = function ($num, $den, $digit = 2) {
        $num = (float) $num;
        $den = (float) $den;
        if ($den == 0.0) return '0,00%';
        return number_format(($num / $den) * 100, $digit, ',', '.') . '%';
    };

    $delta = (float) ($waterfall['delta'] ?? 0);
    $reconDelta = (float) ($waterfall['recon_delta'] ?? 0);
    $osPrev = (float) ($waterfall['os_prev'] ?? 0);
    $osCurrent = (float) ($waterfall['os_current'] ?? 0);

    $deltaClass = $delta > 0
        ? 'text-emerald-700 bg-emerald-50 border-emerald-200'
        : ($delta < 0
            ? 'text-rose-700 bg-rose-50 border-rose-200'
            : 'text-slate-700 bg-slate-50 border-slate-200');

    $sourceBadge = !empty($waterfall['is_live'])
        ? 'bg-sky-50 text-sky-700 border-sky-200'
        : 'bg-slate-50 text-slate-700 border-slate-200';
@endphp

<div class="rounded-[28px] border border-slate-200 bg-white overflow-hidden shadow-sm">
    <div class="px-6 sm:px-8 py-6 sm:py-7 border-b border-slate-200">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h3 class="text-[20px] sm:text-[22px] font-extrabold tracking-tight text-slate-900">
                    Pergerakan Portofolio Kredit
                </h3>
                <p class="mt-2 text-sm sm:text-[15px] text-slate-500">
                    Ringkasan perubahan outstanding dari awal periode ke posisi akhir periode.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold {{ $sourceBadge }}">
                    {{ !empty($waterfall['is_live']) ? 'Sumber akhir: Live Position' : 'Sumber akhir: Snapshot Bulanan' }}
                </span>

                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold {{ $deltaClass }}">
                    Δ Portofolio: {{ $fmtMoney($delta) }}
                </span>
            </div>
        </div>
    </div>

    <div class="p-6 sm:p-8">
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
            {{-- Tabel utama --}}
            <div class="xl:col-span-2">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm text-slate-800">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500">
                                <th class="text-left py-3 pr-4 font-semibold">Komponen</th>
                                <th class="text-right py-3 pl-4 font-semibold">Nominal</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100">
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-4 pr-4 font-semibold text-slate-900">OS Awal Bulan</td>
                                <td class="py-4 pl-4 text-right font-semibold tabular-nums text-slate-900">
                                    {{ $fmtMoney($waterfall['os_prev'] ?? 0) }}
                                </td>
                            </tr>

                            <tr class="hover:bg-emerald-50/40 transition-colors">
                                <td class="py-4 pr-4 font-semibold text-emerald-800">+ Disbursement</td>
                                <td class="py-4 pl-4 text-right font-semibold tabular-nums text-emerald-700">
                                    {{ $fmtMoney($waterfall['disbursement'] ?? 0) }}
                                </td>
                            </tr>

                            <tr class="hover:bg-rose-50/40 transition-colors">
                                <td class="py-4 pr-4 font-semibold text-rose-800">- Pelunasan</td>
                                <td class="py-4 pl-4 text-right font-semibold tabular-nums text-rose-700">
                                    {{ $fmtMoney($waterfall['pelunasan'] ?? 0) }}
                                </td>
                            </tr>

                            <tr class="bg-slate-100">
                                <td class="py-4 pr-4 font-extrabold text-slate-900">OS Akhir Bulan</td>
                                <td class="py-4 pl-4 text-right font-extrabold tabular-nums text-slate-900">
                                    {{ $fmtMoney($waterfall['os_current'] ?? 0) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Ringkasan kanan --}}
            <div class="space-y-4">
                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">
                        Growth vs Awal Bulan
                    </div>
                    <div class="mt-2 text-2xl font-extrabold {{ $delta > 0 ? 'text-emerald-700' : ($delta < 0 ? 'text-rose-700' : 'text-slate-900') }}">
                        {{ $fmtPct($delta, $osPrev) }}
                    </div>
                    <div class="mt-1 text-sm text-slate-500">
                        Perubahan OS akhir dibanding posisi awal bulan.
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">
                        Net Aktivitas Kredit
                    </div>
                    <div class="mt-2 text-xl font-extrabold text-slate-900 tabular-nums">
                        {{ $fmtMoney($reconDelta) }}
                    </div>
                    <div class="mt-1 text-sm text-slate-500">
                        Disbursement dikurangi pelunasan.
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500">
                        Closing Position
                    </div>
                    <div class="mt-2 text-xl font-extrabold text-slate-900 tabular-nums">
                        {{ $fmtMoney($osCurrent) }}
                    </div>
                    <div class="mt-1 text-sm text-slate-500">
                        {{ !empty($waterfall['is_live']) ? 'Menggunakan posisi live terbaru.' : 'Menggunakan snapshot bulanan periode terpilih.' }}
                    </div>
                </div>
            </div>
        </div>

        {{-- Catatan bawah --}}
        <div class="mt-5 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
            <span class="font-semibold text-slate-800">Catatan:</span>
            Selisih antara perubahan OS aktual dan net aktivitas kredit dapat dipengaruhi oleh angsuran pokok,
            koreksi data, restrukturisasi, write-off, atau perubahan saldo pada rekening yang tetap aktif.
        </div>
    </div>
</div>