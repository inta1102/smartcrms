{{-- =============================
    TABEL A) LT EOM -> DPK (Risk Escalation)
============================= --}}
<div class="rounded-2xl border border-slate-200 bg-white overflow-hidden mt-4">
    <div class="p-4 border-b border-slate-200">
        <div class="font-extrabold text-slate-900 text-lg">
            LT (EOM Bulan Lalu) → DPK (Hari Ini)
        </div>
        <div class="text-sm text-slate-600 mt-1">
            Fokus: akun yang memburuk (indikasi risiko naik). Total:
            <b>{{ (int)($ltToDpkNoa ?? 0) }}</b> NOA
            · OS:
            <b>Rp {{ number_format((int)($ltToDpkOs ?? 0),0,',','.') }}</b>
        </div>
    </div>

    <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr class="border-b">
                    <th class="text-left px-3 py-2">No Rek</th>
                    <th class="text-left px-3 py-2">Nama Debitur</th>
                    <th class="text-right px-3 py-2">OS</th>
                    <th class="text-center px-3 py-2">FT Pokok</th>
                    <th class="text-center px-3 py-2">FT Bunga</th>
                    <th class="text-center px-3 py-2">DPD</th>
                    <th class="text-center px-3 py-2">Kolek</th>
                    <th class="text-center px-3 py-2">Progres</th>
                    <th class="text-center px-3 py-2">Tgl Visit Terakhir</th>
                    <th class="text-center px-3 py-2">Umur Visit</th>
                    <th class="text-center px-3 py-2">Plan Visit</th>
                    <th class="text-center px-3 py-2">Tgl Plan Visit</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-slate-100">
                @forelse(($ltToDpkList ?? []) as $r)
                    @php
                        $acc = (string)($r->account_no ?? '');

                        $lastVisitAt = $r->last_visit_date ?? null;
                        $ageDays = null;
                        if ($lastVisitAt) {
                            try {
                                $ageDays = \Carbon\Carbon::parse($lastVisitAt)->diffInDays(now());
                            } catch (\Throwable $e) {
                                $ageDays = null;
                            }
                        }

                        $plannedToday = (int)($r->planned_today ?? 0) === 1;
                        $planStatus   = strtolower(trim((string)($r->plan_status ?? '')));
                        $planVisit    = (string)($r->plan_visit_date ?? '');
                    @endphp

                    <tr>
                        <td class="px-3 py-2 font-mono">{{ $acc }}</td>
                        <td class="px-3 py-2 font-semibold">{{ $r->customer_name }}</td>
                        <td class="px-3 py-2 text-right">
                            Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}
                        </td>
                        <td class="px-3 py-2 text-center">{{ (int)($r->ft_pokok ?? 0) }}</td>
                        <td class="px-3 py-2 text-center">{{ (int)($r->ft_bunga ?? 0) }}</td>
                        <td class="px-3 py-2 text-center">{{ (int)($r->dpd ?? 0) }}</td>
                        <td class="px-3 py-2 text-center">{{ (int)($r->kolek ?? 0) }}</td>

                        <td class="px-3 py-2 text-center">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                LT→DPK
                            </span>
                        </td>

                        <td class="px-3 py-2 text-center whitespace-nowrap">
                            {{ $lastVisitAt ? \Carbon\Carbon::parse($lastVisitAt)->format('d/m/Y') : '-' }}
                        </td>

                        <td class="px-3 py-2 text-center whitespace-nowrap">
                            {{ is_null($ageDays) ? '-' : floor($ageDays).' hari' }}
                        </td>

                        <td class="px-3 py-2 text-center whitespace-nowrap">
                            @php
                                $isPlanned = (int)($plannedToday ?? 0) === 1;
                            @endphp

                            <button
                                type="button"
                                class="ro-plan-today-btn inline-flex items-center justify-center rounded-full px-4 py-2 text-xs font-bold border
                                {{ $isPlanned ? 'bg-slate-900 text-white border-slate-900 opacity-80 cursor-not-allowed' : 'bg-white text-slate-800 border-slate-300 hover:bg-slate-50' }}"
                                data-account="{{ $acc }}"
                                data-url="{{ route('ro_visits.plan_today') }}"
                                {{ $isPlanned ? 'disabled' : '' }}
                            >
                                {{ $isPlanned ? 'Planned' : 'Ya' }}
                            </button>
                        </td>

                        <td class="px-3 py-2 text-center whitespace-nowrap">
                            <span class="ro-plan-date font-semibold text-slate-700" data-account="{{ $acc }}">
                                {{ $planVisit !== '' ? \Carbon\Carbon::parse($planVisit)->format('d/m/Y') : '-' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" class="px-3 py-6 text-center text-slate-500">
                            Tidak ada akun LT yang berubah menjadi DPK pada periode ini ✅
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>