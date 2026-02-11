@extends('layouts.app')

@section('title', 'Dashboard RO - OS Harian')

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-5">

  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">📈 Dashboard RO – OS Harian</h1>
      <p class="text-sm text-slate-500">
        Scope: <b>RO sendiri</b>. Data snapshot harian (kpi_os_daily_aos). Posisi terakhir: <b>{{ $latestPosDate }}</b>.
      </p>
    </div>

    <form method="GET" class="flex items-end gap-2 flex-wrap">
      <div>
        <div class="text-xs text-slate-500 mb-1">Dari</div>
        <input type="date" name="from" value="{{ $from }}"
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
      </div>
      <div>
        <div class="text-xs text-slate-500 mb-1">Sampai</div>
        <input type="date" name="to" value="{{ $to }}"
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
      </div>
      <button class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
        Tampilkan
      </button>
    </form>
  </div>

  {{-- Summary --}}
  <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">Latest OS</div>
      <div class="text-lg font-extrabold text-slate-900">Rp {{ number_format((int)$latestOs,0,',','.') }}</div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">Latest L0</div>
      <div class="text-lg font-extrabold text-slate-900">Rp {{ number_format((int)$latestL0,0,',','.') }}</div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">Latest LT</div>
      <div class="text-lg font-extrabold text-slate-900">Rp {{ number_format((int)$latestLT,0,',','.') }}</div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">RR (%L0)</div>
      <div class="text-lg font-extrabold text-slate-900">{{ is_null($latestRR) ? '-' : number_format((float)$latestRR,2,',','.') . '%' }}</div>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">%LT</div>
      <div class="text-lg font-extrabold text-slate-900">{{ is_null($latestPctLt) ? '-' : number_format((float)$latestPctLt,2,',','.') . '%' }}</div>
    </div>
  </div>

  {{-- Insight box --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-4">
    <div class="font-extrabold text-slate-900">🧠 Catatan Kinerja (Auto Insight)</div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-3">
      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
        <div class="text-xs font-bold text-slate-700 mb-2">Yang Baik</div>
        <ul class="text-sm text-slate-700 space-y-1 list-disc pl-5">
          @forelse(($insight['good'] ?? []) as $t)
            <li>{{ $t }}</li>
          @empty
            <li>-</li>
          @endforelse
        </ul>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
        <div class="text-xs font-bold text-slate-700 mb-2">Yang Buruk / Perlu Aksi</div>
        <ul class="text-sm text-slate-700 space-y-1 list-disc pl-5">
          @forelse(($insight['bad'] ?? []) as $t)
            <li>{{ $t }}</li>
          @empty
            <li>-</li>
          @endforelse
        </ul>
      </div>

      <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
        <div class="text-xs font-bold text-slate-700 mb-2">Penyebab (indikasi)</div>
        <ul class="text-sm text-slate-700 space-y-1 list-disc pl-5">
          @foreach(($insight['why'] ?? []) as $t)
            <li>{{ $t }}</li>
          @endforeach
        </ul>
      </div>
    </div>
  </div>

  {{-- Grafik 5 garis --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-4 space-y-4">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <div>
        <div class="font-bold text-slate-900">Grafik Harian (5 garis)</div>
        <div class="text-xs text-slate-500">
          Tanggal tanpa snapshot akan tampil <b>putus</b> (bukan 0).
        </div>
      </div>

      <div class="rounded-xl border border-slate-200 p-1 bg-slate-50 flex items-center gap-1">
        <button type="button" id="btnModeValue"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200">Value</button>
        <button type="button" id="btnModeGrowth"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">Growth (Δ H vs H-1)</button>
      </div>
    </div>

    <div class="w-full overflow-x-auto">
      <canvas id="roChart" height="120"></canvas>
    </div>
  </div>

  {{-- ===========================
    1) JT bulan ini
    =========================== --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
        <div class="font-bold text-slate-900">Debitur Jatuh Tempo – {{ $dueMonthLabel ?? now()->translatedFormat('F Y') }}</div>
        <div class="text-xs text-slate-500 mt-1">
        Sumber: maturity_date (tgl_jto). Scope RO sendiri.
        </div>
    </div>

    <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
            <tr class="text-slate-700">
            <th class="text-left px-3 py-2">Jatuh Tempo</th>
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
            @forelse(($dueThisMonth ?? []) as $r)
            <tr>
                <td class="px-3 py-2 whitespace-nowrap">{{ \Carbon\Carbon::parse($r->maturity_date)->format('d/m/Y') }}</td>
                <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
                <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
                <td class="px-3 py-2 text-right">Rp {{ number_format((int)($r->outstanding ?? 0),0,',','.') }}</td>
                <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
                <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-3 py-6 text-center text-slate-500">Tidak ada JT bulan ini.</td>
            </tr>
            @endforelse
        </tbody>
        </table>
    </div>
    </div>

    {{-- ===========================
        2) LT posisi terakhir
        =========================== --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
        <div class="font-bold text-slate-900">LT (FT = 1) – Posisi Terakhir</div>
        <div class="text-xs text-slate-500 mt-1">
        Definisi: ft_pokok = 1 atau ft_bunga = 1. Posisi: <b>{{ $latestPosDate ?? '-' }}</b>.
        </div>
    </div>

    <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
            <tr class="text-slate-700">
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
            @forelse(($ltLatest ?? []) as $r)
            <tr>
                <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
                <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
                <td class="px-3 py-2 text-right">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
                <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
                <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="px-3 py-6 text-center text-slate-500">Tidak ada LT pada posisi terakhir.</td>
            </tr>
            @endforelse
        </tbody>
        </table>
    </div>
    </div>

    {{-- ===========================
        3) JT angsuran minggu ini (FIX)
        =========================== --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
        <div class="font-bold text-slate-900">
        JT Angsuran Minggu Ini
        @if(!empty($weekStart) && !empty($weekEnd))
            <span class="text-slate-500 font-normal text-sm">({{ $weekStart }} s/d {{ $weekEnd }})</span>
        @endif
        </div>
        <div class="text-xs text-slate-500 mt-1">
        Sumber: installment_day. Dibaca terhadap posisi terakhir: <b>{{ $latestPosDate ?? '-' }}</b>.
        </div>
    </div>

    <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
            <tr class="text-slate-700">
            <th class="text-left px-3 py-2">JT (Tanggal)</th>
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>
            </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
            @php
            $pos = !empty($latestPosDate) ? \Carbon\Carbon::parse($latestPosDate) : now();
            @endphp

            @forelse(($jtAngsuran ?? []) as $r)
            @php
                $day = (int)($r->installment_day ?? 0);
                $day = $day > 0 ? min(max($day, 1), $pos->daysInMonth) : null;
                $due = $day ? $pos->copy()->day($day) : null;
            @endphp
            <tr>
                <td class="px-3 py-2 whitespace-nowrap">{{ $due ? $due->format('d/m/Y') : '-' }}</td>
                <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
                <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
                <td class="px-3 py-2 text-right">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
                <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
                <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="8" class="px-3 py-6 text-center text-slate-500">Tidak ada JT angsuran minggu ini.</td>
            </tr>
            @endforelse
        </tbody>
        </table>
    </div>
    </div>

    {{-- ===========================
        4) OS > 500jt
        =========================== --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
        <div class="font-bold text-slate-900">OS ≥ 500 Juta – Posisi Terakhir</div>
        <div class="text-xs text-slate-500 mt-1">
        Filter: outstanding ≥ 500.000.000. Posisi: <b>{{ $latestPosDate ?? '-' }}</b>.
        </div>
    </div>

    <div class="p-4 overflow-x-auto">
        <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
            <tr class="text-slate-700">
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>
            </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
            @forelse(($osBig ?? []) as $r)
            <tr>
                <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
                <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
                <td class="px-3 py-2 text-right">Rp {{ number_format((int)($r->os ?? 0),0,',','.') }}</td>
                <td class="px-3 py-2 text-right">{{ (int)($r->ft_pokok ?? 0) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)($r->ft_bunga ?? 0) }}</td>
                <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
                <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>
            </tr>
            @empty
            <tr>
                <td colspan="7" class="px-3 py-6 text-center text-slate-500">Tidak ada OS ≥ 500 juta.</td>
            </tr>
            @endforelse
        </tbody>
        </table>
    </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  const labels = @json($labels);
  const series = @json($series);

  const isNil = v => v === null || typeof v === 'undefined';

  function toGrowth(arr){
    const out = [];
    for (let i=0;i<arr.length;i++){
      const cur = arr[i];
      const prev = i>0 ? arr[i-1] : null;
      if (isNil(cur) || isNil(prev)) out.push(null);
      else out.push(Number(cur)-Number(prev));
    }
    return out;
  }

  function fmtRp(v){ return 'Rp ' + Number(v||0).toLocaleString('id-ID'); }
  function fmtPct(v){ return Number(v||0).toLocaleString('id-ID',{maximumFractionDigits:2}) + '%'; }

  let mode = 'value'; // value|growth

  const ctx = document.getElementById('roChart').getContext('2d');

  const chart = new Chart(ctx,{
    type:'line',
    data:{ labels, datasets:[] },
    options:{
      responsive:true,
      interaction:{ mode:'index', intersect:false },
      plugins:{
        legend:{ display:true },
        tooltip:{
          callbacks:{
            label:(c)=>{
              const v = c.raw;
              if (isNil(v)) return `${c.dataset.label}: (no data)`;
              const isPct = c.dataset.yAxisID === 'yPct';
              const sign = (mode==='growth' && Number(v)>=0) ? '+' : '';
              return `${c.dataset.label}: ${sign}${isPct ? fmtPct(v) : fmtRp(v)}`;
            }
          }
        }
      },
      scales:{
        yRp:{
          type:'linear',
          position:'left',
          ticks:{ callback:(v)=>'Rp '+Number(v).toLocaleString('id-ID') }
        },
        yPct:{
          type:'linear',
          position:'right',
          grid:{ drawOnChartArea:false },
          ticks:{ callback:(v)=>Number(v).toLocaleString('id-ID',{maximumFractionDigits:2})+'%' }
        }
      }
    }
  });

  function rebuild(){
    const s = (k)=> (mode==='growth') ? toGrowth(series[k]||[]) : (series[k]||[]);
    chart.data.datasets = [
      { label:'OS Total', data:s('os_total'), yAxisID:'yRp', tension:0.2, spanGaps:false, borderWidth:2, pointRadius:0 },
      { label:'OS L0',    data:s('os_l0'),    yAxisID:'yRp', tension:0.2, spanGaps:false, borderWidth:2, pointRadius:0 },
      { label:'OS LT',    data:s('os_lt'),    yAxisID:'yRp', tension:0.2, spanGaps:false, borderWidth:2, pointRadius:0 },
      { label:'RR (%L0)', data:s('rr'),       yAxisID:'yPct',tension:0.2, spanGaps:false, borderWidth:2, pointRadius:0 },
      { label:'%LT',      data:s('pct_lt'),   yAxisID:'yPct',tension:0.2, spanGaps:false, borderWidth:2, pointRadius:0 },
    ];
    chart.update();
  }

  const btnValue = document.getElementById('btnModeValue');
  const btnGrowth= document.getElementById('btnModeGrowth');

  function paint(){
    if (mode==='value'){
      btnValue.className='px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
      btnGrowth.className='px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
    } else {
      btnValue.className='px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
      btnGrowth.className='px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
    }
  }

  btnValue.addEventListener('click', ()=>{ mode='value'; paint(); rebuild(); });
  btnGrowth.addEventListener('click',()=>{ mode='growth'; paint(); rebuild(); });

  paint();
  rebuild();
</script>
@endsection
