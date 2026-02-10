@extends('layouts.app')

@section('title', 'KPI Sheet')

@section('content')
@php
  // Pastikan periodYm selalu tersedia dari controller atau fallback dari request
  $periodYm = $periodYm ?? request('period', $period->format('Y-m'));
  $roleSel  = $role ?? request('role', 'SO');

  // ✅ period date (Y-m-01) buat parameter route
  $periodYmd = $periodYm . '-01';

  // ✅ tombol "Buat Target" harus ngarah sesuai role
  // $createTargetUrl = $roleSel === 'SO'
  //    ? route('kpi.so.targets.index', ['period' => $periodYmd])
  //    : route('kpi.marketing.targets.create', ['period' => $periodYmd]); // AO/marketing

  // ✅ akses input komunitas hanya KBL (sesuai request terbaru)
  $canInputSoCommunity = $roleSel === 'SO' && auth()->user()?->hasAnyRole(['KBL']);
@endphp

<div class="max-w-6xl mx-auto p-4">

  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">📄 KPI {{ $roleSel }} – Sheet</h1>
      <p class="text-sm text-slate-500">
        Periode: <b>{{ $period->translatedFormat('M Y') }}</b>
      </p>
    </div>

    <div class="flex items-center gap-2 flex-wrap">

      {{-- ✅ FILTER (GET) --}}
      <form method="GET" class="flex items-center gap-2">
        <select name="role" class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
          <option value="AO" {{ $roleSel==='AO'?'selected':'' }}>AO</option>
          <option value="SO" {{ $roleSel==='SO'?'selected':'' }}>SO</option>
        </select>

        <input type="month"
               name="period"
               value="{{ $periodYm }}"
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm"/>

        <button class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
          Tampilkan
        </button>
      </form>

      {{-- ✅ ACTIONS (POST/LINK) - JANGAN di dalam form GET --}}
      <div class="flex gap-2">

        @if($roleSel === 'SO' && auth()->user()?->hasAnyRole(['KBL']))
          <a href="{{ route('kpi.so.targets.index', ['period' => $periodYm]) }}"
            class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
            + Buat Target SO
          </a>
        @endif

        {{-- ✅ Input Community Handling + OS Adjustment (KBL only, SO only) --}}
        @if($canInputSoCommunity)
          <a href="{{ route('kpi.so.community_input.index', ['period' => $periodYmd]) }}"
             class="rounded-xl bg-indigo-600 px-4 py-2 text-white text-sm font-semibold hover:bg-indigo-700">
            ✍️ Input Komunitas & Adjustment
          </a>
        @endif

        {{-- Recalc hanya untuk yang punya permission --}}
        @can('recalcMarketingKpi')

          @if($roleSel === 'AO')
            {{-- Recalc AO --}}
            <form method="POST"
                  action="{{ route('kpi.recalc.ao') }}"
                  onsubmit="return confirm('Recalc KPI AO untuk periode ini?')">
              @csrf
              <input type="hidden" name="period" value="{{ $periodYm }}">
              <input type="hidden" name="role" value="{{ $roleSel }}">
              <button class="rounded-xl bg-amber-600 px-4 py-2 text-white text-sm font-semibold hover:bg-amber-700">
                🔄 Recalc AO
              </button>
            </form>

          @elseif($roleSel === 'SO')
            {{-- Recalc SO --}}
            <form method="POST"
                  action="{{ route('kpi.recalc.so') }}"
                  onsubmit="return confirm('Recalc KPI SO untuk periode ini?')">
              @csrf
              <input type="hidden" name="period" value="{{ $periodYm }}">
              <input type="hidden" name="role" value="{{ $roleSel }}">
              <button class="rounded-xl bg-sky-600 px-4 py-2 text-white text-sm font-semibold hover:bg-sky-700">
                🔄 Recalc SO
              </button>
            </form>
          @endif

        @endcan

      </div>

    </div>
  </div>

  @if($items->isEmpty())
    <div class="rounded-2xl border border-slate-200 bg-white p-6 text-slate-600">
      Belum ada data KPI {{ $roleSel }} untuk periode ini. Jalankan <b>Recalc</b> untuk menghitung KPI.
    </div>
  @else

    <div class="space-y-6">
      @foreach($items as $it)
        @php
          // ✅ Support tampilan Raw/Adj jika kolom tersedia di hasil query (service sudah conditional write)
          $osRaw = $it->os_disbursement_raw ?? null;
          $osAdj = $it->os_adjustment ?? null;

          $hasOsMeta = !is_null($osRaw) || !is_null($osAdj);
          $osAdjVal  = (int)($osAdj ?? 0);
        @endphp

        <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b border-slate-200 flex items-start justify-between gap-3">
            <div>
              <div class="text-lg font-extrabold text-slate-900 uppercase">
                {{ $it->name }}
              </div>
              <div class="text-xs text-slate-500">
                {{ $it->level }} • AO Code: <b>{{ $it->ao_code ?: '-' }}</b>
              </div>
            </div>

            <div class="text-right">
              <div class="text-xs text-slate-500">Total PI</div>
              <div class="text-xl font-extrabold text-slate-900">
                {{ number_format((float)($it->pi_total ?? 0), 2) }}
              </div>
            </div>
          </div>

          <div class="p-4 overflow-x-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-orange-500 text-white">
                <tr>
                  <th class="text-left px-3 py-2">KPI</th>
                  <th class="text-right px-3 py-2">Target</th>
                  <th class="text-right px-3 py-2">Actual</th>
                  <th class="text-right px-3 py-2">Pencapaian</th>
                  <th class="text-center px-3 py-2">Skor</th>
                  <th class="text-right px-3 py-2">Bobot</th>
                  <th class="text-right px-3 py-2">PI</th>
                </tr>
              </thead>

              <tbody class="divide-y divide-slate-200">
                @if($roleSel==='SO')
                  <tr>
                    <td class="px-3 py-2 font-semibold">
                      Target OS Realisasi
                      @if(($it->os_adjustment ?? 0) > 0)
                        <span class="ml-1 inline-block rounded bg-amber-100 text-amber-800 text-xs px-2 py-0.5">
                          adjusted
                        </span>
                      @endif
                    </td>


                    <td class="px-3 py-2 text-right">
                      Rp {{ number_format((int)($it->target_os_disbursement ?? 0),0,',','.') }}
                    </td>

                    <td class="px-3 py-2 text-right">
                      {{-- ✅ Actual OS yang tampil = NET (service sudah simpan os_disbursement = net) --}}
                      Rp {{ number_format((int)($it->os_disbursement ?? 0),0,',','.') }}

                      {{-- ✅ info raw & adjustment (jika tersedia) --}}
                      @if($hasOsMeta && $osAdjVal > 0)
                        <div class="text-xs text-slate-500 mt-1">
                          Total realisasi: Rp {{ number_format((int)($osRaw ?? 0),0,',','.') }} <br>
                          Dikurangi penyesuaian: Rp {{ number_format((int)($osAdjVal),0,',','.') }} <br>
                          <span class="italic text-slate-400">
                            (yang dihitung sebagai kinerja SO: Rp {{ number_format((int)($it->os_disbursement ?? 0),0,',','.') }})
                          </span>
                        </div>
                      @endif

                    </td>

                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_os ?? 0),2) }}%</td>
                    <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_os ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">{{ (int)round($weights['os']*100) }}%</td>
                    <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_os ?? 0),2) }}</td>
                  </tr>

                  <tr>
                    <td class="px-3 py-2 font-semibold">Target NOA</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->target_noa_disbursement ?? 0),2) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->noa_disbursement ?? 0),2) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_noa ?? 0),2) }}%</td>
                    <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_noa ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">{{ (int)round($weights['noa']*100) }}%</td>
                    <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_noa ?? 0),2) }}</td>
                  </tr>

                  <tr>
                    <td class="px-3 py-2 font-semibold">Repayment Rate</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->target_rr ?? 100),0) }}%</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->rr_pct ?? 0),2) }}%</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_rr ?? 0),2) }}%</td>
                    <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_rr ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">{{ (int)round($weights['rr']*100) }}%</td>
                    <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_rr ?? 0),2) }}</td>
                  </tr>

                  <tr>
                    <td class="px-3 py-2 font-semibold">Handling Komunitas</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->target_activity ?? 0),2) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->activity_actual ?? 0),2) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_activity ?? 0),2) }}%</td>
                    <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_activity ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">{{ (int)round($weights['activity']*100) }}%</td>
                    <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_activity ?? 0),2) }}</td>
                  </tr>
                @else
                  <tr>
                    <td class="px-3 py-2 font-semibold">Target OS Growth</td>
                    <td class="px-3 py-2 text-right">Rp {{ number_format((int)($it->target_os_growth ?? 0),0,',','.') }}</td>
                    <td class="px-3 py-2 text-right">Rp {{ number_format((int)($it->os_growth ?? 0),0,',','.') }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_os ?? 0),2) }}%</td>
                    <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_os ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">{{ (int)round($weights['os']*100) }}%</td>
                    <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_os ?? 0),2) }}</td>
                  </tr>

                  <tr>
                    <td class="px-3 py-2 font-semibold">Target NOA Growth</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->target_noa_growth ?? 0),2) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->noa_growth ?? 0),2) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_noa ?? 0),2) }}%</td>
                    <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_noa ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">{{ (int)round($weights['noa']*100) }}%</td>
                    <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_noa ?? 0),2) }}</td>
                  </tr>

                  <tr>
                    <td class="px-3 py-2 font-semibold">Repayment Rate</td>
                    <td class="px-3 py-2 text-right">100%</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->rr_pct ?? 0),2) }}%</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_rr ?? 0),2) }}%</td>
                    <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_rr ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">{{ (int)round($weights['rr']*100) }}%</td>
                    <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_rr ?? 0),2) }}</td>
                  </tr>

                  <tr>
                    <td class="px-3 py-2 font-semibold">Kualitas Kolek / Migrasi NPL</td>
                    <td class="px-3 py-2 text-right">-</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->npl_migration_pct ?? 0),2) }}%</td>
                    <td class="px-3 py-2 text-right">-</td>
                    <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_kolek ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">{{ (int)round($weights['kolek']*100) }}%</td>
                    <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_kolek ?? 0),2) }}</td>
                  </tr>

                  <tr>
                    <td class="px-3 py-2 font-semibold">Activity</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->target_activity ?? 0),2) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->activity_actual ?? 0),2) }}</td>
                    <td class="px-3 py-2 text-right">{{ number_format((float)($it->ach_activity ?? 0),2) }}%</td>
                    <td class="px-3 py-2 text-center font-bold">{{ (int)($it->score_activity ?? 0) }}</td>
                    <td class="px-3 py-2 text-right">{{ (int)round($weights['activity']*100) }}%</td>
                    <td class="px-3 py-2 text-right font-bold">{{ number_format((float)($it->pi_activity ?? 0),2) }}</td>
                  </tr>
                @endif
              </tbody>

              <tfoot>
                <tr class="bg-yellow-200">
                  <td colspan="6" class="px-3 py-2 font-extrabold text-right">TOTAL</td>
                  <td class="px-3 py-2 font-extrabold text-right">
                    {{ number_format((float)($it->pi_total ?? 0),2) }}
                  </td>
                </tr>
              </tfoot>

            </table>
          </div>
        </div>
      @endforeach
    </div>

  @endif

</div>
@endsection
