@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">🏆 Ranking AO – KPI Marketing</h1>
            <p class="text-sm text-slate-500">
                Periode: <b>{{ $period->translatedFormat('M Y') }}</b>
            </p>
        </div>

        <form method="GET" class="flex items-center gap-2">
            <input type="month"
                   name="period"
                   value="{{ $period->format('Y-m') }}"
                   class="rounded-xl border border-slate-300 px-3 py-2 text-sm"
            />
            <input type="hidden" name="tab" value="{{ $tab }}">
            <button class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
                Tampilkan
            </button>
        </form>

        <a href="{{ route('kpi.marketing.sheet', ['role'=>'AO', 'period'=>$period->format('Y-m')]) }}"
            class="rounded-xl bg-white border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">
            📄 KPI Sheet AO
        </a>

        <a href="{{ route('kpi.marketing.sheet', ['role'=>'SO', 'period'=>$period->format('Y-m')]) }}"
            class="rounded-xl bg-white border border-slate-300 px-4 py-2 text-sm font-semibold hover:bg-slate-50">
            📄 KPI Sheet SO
        </a>

        @can('recalcMarketingKpi')
            <form method="POST"
                  action="{{ route('kpi.marketing.ranking.recalc') }}"
                  onsubmit="return confirm('Recalc KPI semua AO untuk periode ini?')">
                @csrf
                <input type="hidden" name="period" value="{{ $period->format('Y-m') }}">
                <button class="w-full md:w-auto rounded-xl bg-amber-600 px-4 py-2 text-white text-sm font-semibold hover:bg-amber-700">
                    🔄 Recalc All AO
                </button>
            </form>
        @endcan

    </div>

    {{-- Tabs --}}
    <div class="mt-3 mb-4 flex gap-2">
        <a href="{{ route('kpi.marketing.ranking.index', ['period'=>$period->format('Y-m'), 'tab'=>'score']) }}"
           class="rounded-xl px-4 py-2 text-sm font-semibold border
                  {{ $tab==='score' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
            Ranking KPI (Score)
        </a>

        <a href="{{ route('kpi.marketing.ranking.index', ['period'=>$period->format('Y-m'), 'tab'=>'growth']) }}"
           class="rounded-xl px-4 py-2 text-sm font-semibold border
                  {{ $tab==='growth' ? 'bg-slate-900 text-white border-slate-900' : 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' }}">
            Ranking Growth (Realisasi)
        </a>
    </div>

    @if($tab==='score')
        <div class="text-xs text-slate-500 mb-2">
            Ranking KPI resmi (butuh target). Urut: Total Score → Score OS → Score NOA → OS Growth.
        </div>
    @else
        <div class="text-xs text-slate-500 mb-2">
            Ranking realisasi (snapshot {{ $prevPeriod->translatedFormat('M Y') }} vs live {{ $period->translatedFormat('M Y') }}).
            Urut: OS Growth → NOA Growth.
        </div>
    @endif

    @php
        // rows untuk tab aktif
        $rows = $tab==='score' ? $scoreRows : $growthRows;
        $top  = $rows->take(3)->values();
        $labels = ['🥇 Juara 1','🥈 Juara 2','🥉 Juara 3'];

        /**
         * Resolver aman untuk semua row (Eloquent vs stdClass)
         * - scoreRows: pakai relation user
         * - growthRows: pakai field user_id/ao_name/ao_code hasil query
         */
        $resolveRow = function ($r) {
            $uid    = $r->user?->id ?? ($r->user_id ?? null);
            $name   = $r->user?->name ?? ($r->ao_name ?? '-');
            $aoCode = $r->user?->ao_code ?? ($r->ao_code ?? '-');

            return [$uid, $name, $aoCode];
        };
    @endphp

    {{-- Top 3 --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
        @for($i=0; $i<3; $i++)
            @php $r = $top[$i] ?? null; @endphp

            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <div class="text-xs text-slate-500">{{ $labels[$i] }}</div>

                @if($r)
                    @php [$uid,$name,$aoCode] = $resolveRow($r); @endphp

                    <div class="mt-1 text-lg font-bold text-slate-900">
                        {{ $name }}
                    </div>
                    <div class="text-xs text-slate-500">
                        AO Code: <b>{{ $aoCode }}</b>
                    </div>

                    @if($tab==='score')
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <div class="rounded-xl bg-slate-50 p-2">
                                <div class="text-[11px] text-slate-500">Total Score</div>
                                <div class="font-bold">{{ number_format((float)($r->score_total ?? 0),2) }}</div>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-2">
                                <div class="text-[11px] text-slate-500">OS Growth</div>
                                <div class="font-bold">Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}</div>
                            </div>
                        </div>
                    @else
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <div class="rounded-xl bg-slate-50 p-2">
                                <div class="text-[11px] text-slate-500">OS Growth</div>
                                <div class="font-bold">Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}</div>
                            </div>
                            <div class="rounded-xl bg-slate-50 p-2">
                                <div class="text-[11px] text-slate-500">NOA Growth</div>
                                <div class="font-bold">{{ number_format((int)($r->noa_growth ?? 0)) }}</div>
                            </div>
                        </div>
                    @endif
                @else
                    <div class="mt-2 text-sm text-slate-500">Belum ada data.</div>
                @endif
            </div>
        @endfor
    </div>

    {{-- Tabel Ranking --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="p-4 border-b border-slate-200">
            <div class="text-sm font-bold text-slate-900">Daftar Ranking</div>
            <div class="text-xs text-slate-500">
                @if($tab==='score')
                    Urut: Total Score → Score OS → Score NOA → OS Growth
                @else
                    Urut: OS Growth → NOA Growth (snapshot vs live)
                @endif
            </div>
        </div>

        {{-- Mobile cards (ONLY mobile) --}}
        <div class="md:hidden divide-y divide-slate-200">
            @forelse($rows as $r)
                @php [$uid,$name,$aoCode] = $resolveRow($r); @endphp

                <div class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="font-bold text-slate-900 truncate">
                                #{{ $r->rank }} -
                                @if($uid)
                                    @if(!empty($r->target_id))
                                      <a href="{{ route('kpi.marketing.targets.achievement', [
                                            'target' => $r->target_id,
                                            'period' => $period->toDateString(),
                                        ]) }}"
                                        class="text-blue-600 hover:underline font-semibold">
                                        {{ $name }}
                                      </a>
                                    @else
                                      <span class="font-semibold text-slate-900">
                                        {{ $name }}
                                      </span>
                                      <div class="text-xs text-red-500">
                                        Target belum tersedia (belum dibuat/di-approve)
                                      </div>
                                    @endif
                                @else
                                    {{ $name }}
                                @endif
                            </div>
                            <div class="text-xs text-slate-500">
                                AO Code: <b>{{ $aoCode }}</b>
                            </div>
                        </div>

                        {{-- Status hanya relevan untuk tab score (ada is_final) --}}
                        @if($tab==='score')
                            <span class="shrink-0 inline-flex rounded-full px-3 py-1 text-xs font-semibold
                              {{ ($r->is_final ?? false) ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' }}">
                              {{ ($r->is_final ?? false) ? 'FINAL' : 'ESTIMASI' }}
                            </span>
                        @endif
                    </div>

                    {{-- Body --}}
                    @if($tab==='score')
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <div class="rounded-xl bg-slate-50 p-2">
                                <div class="text-[11px] text-slate-500">OS Growth</div>
                                <div class="font-bold text-slate-900">
                                    Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}
                                </div>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-2">
                                <div class="text-[11px] text-slate-500">NOA Growth</div>
                                <div class="font-bold text-slate-900">
                                    {{ number_format((int)($r->noa_growth ?? 0)) }}
                                </div>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-2 col-span-2">
                                <div class="text-[11px] text-slate-500">Total</div>
                                <div class="font-bold text-slate-900">
                                    {{ number_format((float)($r->score_total ?? 0),2) }}
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="mt-3 grid grid-cols-2 gap-2">
                            <div class="rounded-xl bg-slate-50 p-2 col-span-2">
                                <div class="text-[11px] text-slate-500">OS Growth</div>
                                <div class="font-bold text-slate-900">
                                    Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}
                                </div>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-2">
                                <div class="text-[11px] text-slate-500">OS Prev</div>
                                <div class="font-bold text-slate-900">
                                    Rp {{ number_format((int)($r->os_prev ?? 0),0,',','.') }}
                                </div>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-2">
                                <div class="text-[11px] text-slate-500">OS Now</div>
                                <div class="font-bold text-slate-900">
                                    Rp {{ number_format((int)($r->os_now ?? 0),0,',','.') }}
                                </div>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-2">
                                <div class="text-[11px] text-slate-500">NOA Growth</div>
                                <div class="font-bold text-slate-900">
                                    {{ number_format((int)($r->noa_growth ?? 0)) }}
                                </div>
                            </div>

                            <div class="rounded-xl bg-slate-50 p-2">
                                <div class="text-[11px] text-slate-500">NOA Prev → Now</div>
                                <div class="font-bold text-slate-900">
                                    {{ number_format((int)($r->noa_prev ?? 0)) }} → {{ number_format((int)($r->noa_now ?? 0)) }}
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Info kalau tidak bisa klik karena user belum match --}}
                    @if(!$uid)
                        <div class="mt-2 text-xs text-red-500">
                            Catatan: akun user belum terdaftar / ao_code belum match.
                        </div>
                    @endif
                </div>
            @empty
                <div class="p-4 text-center text-slate-500">
                    Belum ada data ranking untuk periode ini.
                </div>
            @endforelse
        </div>

        {{-- Desktop --}}
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    @if($tab==='score')
                        <tr class="border-b">
                            <th class="text-left px-4 py-3">Rank</th>
                            <th class="text-left px-4 py-3">AO</th>
                            <th class="text-right px-4 py-3">OS Growth</th>
                            <th class="text-right px-4 py-3">NOA Growth</th>
                            <th class="text-right px-4 py-3">Score OS</th>
                            <th class="text-right px-4 py-3">Score NOA</th>
                            <th class="text-right px-4 py-3">Total</th>
                            <th class="text-center px-4 py-3">Status</th>
                        </tr>
                    @else
                        <tr class="border-b">
                            <th class="text-left px-4 py-3">Rank</th>
                            <th class="text-left px-4 py-3">AO</th>
                            <th class="text-right px-4 py-3">OS Prev</th>
                            <th class="text-right px-4 py-3">OS Now</th>
                            <th class="text-right px-4 py-3">OS Growth</th>
                            <th class="text-right px-4 py-3">NOA Prev</th>
                            <th class="text-right px-4 py-3">NOA Now</th>
                            <th class="text-right px-4 py-3">NOA Growth</th>
                        </tr>
                    @endif
                </thead>

                <tbody>
                    @forelse($rows as $r)
                        @php [$uid,$name,$aoCode] = $resolveRow($r); @endphp

                        @if($tab==='score')
                            <tr class="border-b hover:bg-slate-50">
                                <td class="px-4 py-3 font-bold text-slate-900">{{ $r->rank }}</td>

                                <td class="px-4 py-3">
                                    @if($uid)
                                        <a href="{{ route('kpi.marketing.targets.achievement', ['target'=>$r->target_id, 'period'=>$period->toDateString()]) }}"
                                            class="text-xs px-2 py-1 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-700">
                                            {{ $name }}
                                        </a>
                                    @else
                                        <span class="font-semibold text-slate-900">{{ $name }}</span>
                                        <div class="text-xs text-red-500">User belum match</div>
                                    @endif

                                    <div class="text-xs text-gray-500">
                                        AO Code: {{ $aoCode }}
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right">Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format((int)($r->noa_growth ?? 0)) }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format((float)($r->score_os ?? 0),2) }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format((float)($r->score_noa ?? 0),2) }}</td>
                                <td class="px-4 py-3 text-right font-bold text-slate-900">{{ number_format((float)($r->score_total ?? 0),2) }}</td>

                                <td class="px-4 py-3 text-center">
                                    @if($r->is_final ?? false)
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-emerald-100 text-emerald-800">FINAL</span>
                                    @else
                                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold bg-amber-100 text-amber-800">ESTIMASI</span>
                                    @endif
                                </td>
                            </tr>
                        @else
                            <tr class="border-b hover:bg-slate-50">
                                <td class="px-4 py-3 font-bold text-slate-900">{{ $r->rank }}</td>

                                <td class="px-4 py-3">
                                    @if($uid)
                                        @if(!empty($r->target_id))
                                          <a href="{{ route('kpi.marketing.targets.achievement', [
                                                'target' => $r->target_id,
                                                'period' => $period->toDateString(),
                                            ]) }}"
                                            class="text-blue-600 hover:underline font-semibold">
                                            {{ $name }}
                                          </a>
                                        @else
                                          <span class="font-semibold text-slate-900">
                                            {{ $name }}
                                          </span>
                                          <div class="text-xs text-red-500">
                                            Target belum tersedia (belum dibuat/di-approve)
                                          </div>
                                        @endif

                                    @else
                                        <span class="font-semibold text-slate-900">{{ $name }}</span>
                                        <div class="text-xs text-red-500">User belum match</div>
                                    @endif

                                    <div class="text-xs text-gray-500">
                                        AO Code: {{ $aoCode }}
                                    </div>
                                </td>

                                <td class="px-4 py-3 text-right">Rp {{ number_format((int)($r->os_prev ?? 0),0,',','.') }}</td>
                                <td class="px-4 py-3 text-right">Rp {{ number_format((int)($r->os_now ?? 0),0,',','.') }}</td>
                                <td class="px-4 py-3 text-right font-semibold">Rp {{ number_format((int)($r->os_growth ?? 0),0,',','.') }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format((int)($r->noa_prev ?? 0)) }}</td>
                                <td class="px-4 py-3 text-right">{{ number_format((int)($r->noa_now ?? 0)) }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ number_format((int)($r->noa_growth ?? 0)) }}</td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-6 text-center text-slate-500">
                                Belum ada data ranking untuk periode ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
