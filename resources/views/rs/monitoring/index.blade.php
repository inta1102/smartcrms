@extends('layouts.app')

@section('title', 'RS Monitoring')

@section('content')
@php
  $fmt = fn($n) => 'Rp ' . number_format((float)$n, 0, ',', '.');

  // helper link scope (tetap bawa filter yang sudah dipilih)
  $scopeUrl = fn($s) => request()->fullUrlWithQuery(['scope' => $s]);

  // reset scope
  $resetUrl = fn() => request()->fullUrlWithQuery(['scope' => null]);

  // badge helper
  $badge = function(string $txt, string $tone='slate') {
      $map = [
          'red'    => 'bg-rose-50 text-rose-700 border-rose-200',
          'amber'  => 'bg-amber-50 text-amber-800 border-amber-200',
          'emerald'=> 'bg-emerald-50 text-emerald-700 border-emerald-200',
          'slate'  => 'bg-slate-50 text-slate-700 border-slate-200',
          'indigo' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
          'purple' => 'bg-purple-50 text-purple-700 border-purple-200',
          'sky'    => 'bg-sky-50 text-sky-700 border-sky-200',
      ];
      $cls = $map[$tone] ?? $map['slate'];
      return "<span class=\"inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-semibold {$cls}\">{$txt}</span>";
  };

    $visibleAoCodes = $visibleAoCodes ?? null;

@endphp

@if(!empty($scope))
  <script>
    // auto-scroll ke detail
    window.addEventListener('load', () => {
      const el = document.getElementById('rs-detail');
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  </script>
@endif

<div class="space-y-4">
    <div class="flex items-start justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-slate-900">Dashboard Restrukturisasi</h1>
            <p class="text-sm text-slate-500">
                Monitoring khusus RS berdasarkan snapshot posisi (position_date).
            </p>
        </div>
        <div class="mt-1 text-sm text-slate-500">
            Posisi: {{ $filters['position_date'] }}
            <span class="mx-2">•</span>
            Scope:
            @if($visibleAoCodes === null)
                ALL
            @elseif(count($visibleAoCodes) === 1)
                PERSONAL
            @else
                SUBSET ({{ count($visibleAoCodes) }} AO)
            @endif
        </div>

    </div>

    {{-- KPI GRID --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

        {{-- 1 --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">Total OS RS</div>
            <div class="text-2xl font-extrabold text-slate-900">{{ $fmt($kpi['total_os_rs']['value']) }}</div>
            <div class="text-xs text-slate-500 mt-1">{{ $kpi['total_os_rs']['desc'] }}</div>
        </div>

        {{-- 2 --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">Total Rek RS</div>
            <div class="text-2xl font-extrabold text-slate-900">{{ number_format($kpi['total_rek_rs']['value']) }}</div>
            <div class="text-xs text-slate-500 mt-1">{{ $kpi['total_rek_rs']['desc'] }}</div>
        </div>

        {{-- 3 --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-2">
                <div class="text-sm text-slate-500">RS Baru (R0–30)</div>

                <a href="{{ $scopeUrl('r0_30') }}"
                   class="text-[11px] font-semibold text-indigo-700 hover:text-indigo-900">
                    Lihat Detail →
                </a>
            </div>

            <div class="text-2xl font-extrabold text-slate-900">{{ $fmt($kpi['r0_30']['os']) }}</div>
            <div class="text-xs text-slate-500 mt-1">
                Rek: {{ number_format($kpi['r0_30']['rek']) }} • {{ $kpi['r0_30']['pct_os'] }}%
            </div>
            <div class="text-[11px] text-slate-400 mt-1">{{ $kpi['r0_30']['desc'] }}</div>
        </div>

        {{-- 4 --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-2">
                <div class="text-sm text-slate-500">R0–30 sudah DPD &gt; 0</div>

                <a href="{{ $scopeUrl('r0_30_dpd') }}"
                   class="text-[11px] font-semibold text-indigo-700 hover:text-indigo-900">
                    Lihat Detail →
                </a>
            </div>

            <div class="text-2xl font-extrabold text-slate-900">{{ $fmt($kpi['r0_30_dpd']['os']) }}</div>
            <div class="text-xs text-slate-500 mt-1">
                Rek: {{ number_format($kpi['r0_30_dpd']['rek']) }} • {{ $kpi['r0_30_dpd']['pct_os'] }}%
            </div>
            <div class="text-[11px] text-slate-400 mt-1">{{ $kpi['r0_30_dpd']['desc'] }}</div>
        </div>

        {{-- 5 --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-2">
                <div class="flex items-center gap-1 text-sm text-slate-500">
                    RS High Risk
                    <span title="DPD ≥ 15 atau Kolek ≥ 3 atau Frek ≥ 2"
                          class="cursor-help text-slate-400">ⓘ</span>
                </div>

                <a href="{{ $scopeUrl('high_risk') }}"
                   class="text-[11px] font-semibold text-indigo-700 hover:text-indigo-900">
                    Lihat Detail →
                </a>
            </div>

            <div class="text-2xl font-extrabold text-slate-900">{{ $fmt($kpi['high_risk']['os']) }}</div>
            <div class="text-xs text-slate-500 mt-1">
                Rek: {{ number_format($kpi['high_risk']['rek']) }} • {{ $kpi['high_risk']['pct_os'] }}%
            </div>
            <div class="text-[11px] text-slate-400 mt-1">{{ $kpi['high_risk']['desc'] }}</div>
        </div>

        {{-- 6 --}}
        <div class="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-2">
                <div class="text-sm text-slate-500">
                    RS Kritis
                    <span title="DPD ≥ 60 atau Kolek ≥ 4"
                          class="cursor-help text-slate-400">ⓘ</span>
                </div>

                <a href="{{ $scopeUrl('kritis') }}"
                   class="text-[11px] font-semibold text-indigo-700 hover:text-indigo-900">
                    Lihat Detail →
                </a>
            </div>

            <div class="text-2xl font-extrabold text-slate-900">{{ $fmt($kpi['kritis']['os']) }}</div>
            <div class="text-xs text-slate-500 mt-1">
                Rek: {{ number_format($kpi['kritis']['rek']) }} • {{ $kpi['kritis']['pct_os'] }}%
            </div>
            <div class="text-[11px] text-slate-400 mt-1">{{ $kpi['kritis']['desc'] }}</div>
        </div>
    </div>

    {{-- Definisi --}}
    <div class="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-4">
        <h3 class="text-sm font-semibold text-slate-800 mb-2">
            📌 Definisi & Kriteria Risiko Restrukturisasi
        </h3>

        <ul class="text-xs text-slate-600 space-y-1 list-disc pl-5">
            <li><b>RS Baru (R0–30)</b>: Usia restruktur 0–30 hari sejak tanggal restruktur terakhir.</li>
            <li><b>R0–30 DPD &gt; 0</b>: RS baru yang sudah menunggak.</li>
            <li><b>RS High Risk</b>: DPD ≥ 15 <i>atau</i> Kolek ≥ 3 <i>atau</i> Frek ≥ 2.</li>
            <li><b>RS Kritis</b>: DPD ≥ 60 <i>atau</i> Kolek ≥ 4.</li>
        </ul>

        <p class="mt-2 text-[11px] text-slate-500">
            Catatan: Data bersifat snapshot sesuai <i>position_date</i>.
        </p>
    </div>

    {{-- DETAIL PANEL --}}
    <div id="rs-detail" class="mt-6 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-bold text-slate-900">
                    {{ $scopeMeta['title'] ?? 'Detail' }}
                </h2>
                @if(!empty($scopeMeta['desc']))
                    <p class="text-xs text-slate-500 mt-0.5">{{ $scopeMeta['desc'] }}</p>
                @else
                    <p class="text-xs text-slate-500 mt-0.5">
                        Klik “Lihat Detail” pada KPI untuk menampilkan daftar rekening.
                    </p>
                @endif
            </div>

            <div class="flex items-center gap-2">
                @if(!empty($scope))
                    <a href="{{ $resetUrl() }}"
                       class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        Reset Detail
                    </a>
                @endif
            </div>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-slate-500">
                        <th class="py-2 pr-3">#</th>
                        <th class="py-2 pr-3">Rekening</th>
                        <th class="py-2 pr-3">Nasabah</th>
                        <th class="py-2 pr-3">AO</th>
                        <th class="py-2 pr-3">Kolek</th>
                        <th class="py-2 pr-3">DPD</th>
                        <th class="py-2 pr-3">Frek</th>
                        <th class="py-2 pr-3">Tgl RS</th>
                        <th class="py-2 pr-3">Umur</th>
                        <th class="py-2 pr-3">Status</th>
                        <th class="py-2">OS</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                    @forelse(($detailRows ?? collect()) as $i => $r)
                        @php
                            $kolek = (int)($r->kolek ?? 0);
                            $dpd   = (int)($r->dpd ?? 0);
                            $frek  = (int)($r->restructure_freq ?? 0);

                            $kolekTone = $kolek >= 4 ? 'red' : ($kolek >= 3 ? 'amber' : 'emerald');
                            $dpdTone   = $dpd >= 60 ? 'red' : ($dpd >= 30 ? 'amber' : ($dpd >= 15 ? 'purple' : 'slate'));
                            $frekTone  = $frek >= 3 ? 'red' : ($frek >= 2 ? 'amber' : 'slate');

                            $rsDate = $r->last_restructure_date ? \Carbon\Carbon::parse($r->last_restructure_date) : null;
                            $posDate= $r->position_date ? \Carbon\Carbon::parse($r->position_date) : null;
                            $umur = ($rsDate && $posDate) ? $posDate->diffInDays($rsDate) : null;

                            $ao = trim(($r->ao_code ?? '').' - '.($r->ao_name ?? ''));

                            $st = $r->action_status ?? 'none';

                            $stLabel = match($st) {
                                'contacted' => 'Sudah dihubungi',
                                'visit'     => 'Visit',
                                'done'      => 'Selesai',
                                default     => 'Belum ada agenda',
                            };

                            $stTone = match($st) {
                                'contacted' => 'sky',
                                'visit'     => 'amber',
                                'done'      => 'emerald',
                                default     => 'slate',
                            };

                            $backUrl = request()->fullUrl(); // supaya balik tetap scope + filter
                        @endphp
                        <tr>
                            <td class="py-2 pr-3 text-slate-500">{{ $i + 1 }}</td>
                            <td class="py-2 pr-3 font-mono text-slate-800">{{ $r->account_no }}</td>
                            <td class="py-2 pr-3 text-slate-900 font-semibold">{{ $r->customer_name }}</td>
                            <td class="py-2 pr-3 text-slate-700">{{ $ao }}</td>

                            <td class="py-2 pr-3">{!! $badge('K'.$kolek, $kolekTone) !!}</td>
                            <td class="py-2 pr-3">{!! $badge((string)$dpd, $dpdTone) !!}</td>
                            <td class="py-2 pr-3">{!! $badge((string)$frek, $frekTone) !!}</td>

                            <td class="py-2 pr-3 text-slate-700">
                                {{ $rsDate ? $rsDate->format('Y-m-d') : '-' }}
                            </td>
                            <td class="py-2 pr-3 text-slate-700">
                                {{ is_null($umur) ? '-' : ($umur.' hari') }}
                            </td>
                            <td class="py-2 pr-3">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        {!! $badge($stLabel, $stTone) !!}
                                        @if(!empty($r->action_channel))
                                            {!! $badge(strtoupper($r->action_channel), 'indigo') !!}
                                        @endif
                                    </div>

                                    <div class="flex flex-wrap gap-1">
                                        {{-- NONE --}}
                                        <form method="POST" action="{{ route('rs.monitoring.action-status') }}">
                                            @csrf
                                            <input type="hidden" name="loan_account_id" value="{{ $r->id }}">
                                            <input type="hidden" name="position_date" value="{{ \Carbon\Carbon::parse($r->position_date)->format('Y-m-d') }}">
                                            <input type="hidden" name="status" value="none">
                                            <input type="hidden" name="back" value="{{ $backUrl }}">
                                            <button class="px-2 py-1 text-[11px] rounded-md border border-slate-200 hover:bg-slate-50">
                                                Belum
                                            </button>
                                        </form>

                                        {{-- CONTACTED --}}
                                        <form method="POST" action="{{ route('rs.monitoring.action-status') }}">
                                            @csrf
                                            <input type="hidden" name="loan_account_id" value="{{ $r->id }}">
                                            <input type="hidden" name="position_date" value="{{ \Carbon\Carbon::parse($r->position_date)->format('Y-m-d') }}">
                                            <input type="hidden" name="status" value="contacted">
                                            <input type="hidden" name="channel" value="wa">
                                            <input type="hidden" name="back" value="{{ $backUrl }}">
                                            <button class="px-2 py-1 text-[11px] rounded-md border border-slate-200 hover:bg-slate-50">
                                                Hubungi (WA)
                                            </button>
                                        </form>

                                        {{-- VISIT --}}
                                        <form method="POST" action="{{ route('rs.monitoring.action-status') }}">
                                            @csrf
                                            <input type="hidden" name="loan_account_id" value="{{ $r->id }}">
                                            <input type="hidden" name="position_date" value="{{ \Carbon\Carbon::parse($r->position_date)->format('Y-m-d') }}">
                                            <input type="hidden" name="status" value="visit">
                                            <input type="hidden" name="channel" value="visit">
                                            <input type="hidden" name="back" value="{{ $backUrl }}">
                                            <button class="px-2 py-1 text-[11px] rounded-md border border-slate-200 hover:bg-slate-50">
                                                Visit
                                            </button>
                                        </form>

                                        {{-- DONE --}}
                                        <form method="POST" action="{{ route('rs.monitoring.action-status') }}">
                                            @csrf
                                            <input type="hidden" name="loan_account_id" value="{{ $r->id }}">
                                            <input type="hidden" name="position_date" value="{{ \Carbon\Carbon::parse($r->position_date)->format('Y-m-d') }}">
                                            <input type="hidden" name="status" value="done">
                                            <input type="hidden" name="back" value="{{ $backUrl }}">
                                            <button class="px-2 py-1 text-[11px] rounded-md border border-slate-200 hover:bg-slate-50">
                                                Selesai
                                            </button>
                                        </form>
                                    </div>

                                    @if(!empty($r->action_note))
                                        <div class="text-[11px] text-slate-500">
                                            Catatan: {{ $r->action_note }}
                                        </div>
                                    @endif
                                </div>
                                </td>
                            <td class="py-2 text-slate-900 font-semibold">{{ $fmt($r->outstanding ?? 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-6 text-center text-sm text-slate-500">
                                Tidak ada data untuk ditampilkan.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
