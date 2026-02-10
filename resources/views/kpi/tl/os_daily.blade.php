@extends('layouts.app')

@section('title', 'Dashboard TL RO - OS Harian')

@section('content')
<div class="max-w-6xl mx-auto p-4 space-y-5">

  <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
    <div>
      <h1 class="text-2xl font-extrabold text-slate-900">📈 Dashboard TL RO – OS Harian per Staff</h1>
      <p class="text-sm text-slate-500">
        Scope: {{ $aoCount }} staff (bawahan TL). Data dari snapshot harian (kpi_os_daily_aos).
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

  {{-- Summary cards (TOTAL semua staff) --}}
  <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">OS Terakhir (Total)</div>
      <div class="text-xl font-extrabold text-slate-900">
        Rp {{ number_format((int)$latestOs, 0, ',', '.') }}
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">OS H-1 (Total)</div>
      <div class="text-xl font-extrabold text-slate-900">
        Rp {{ number_format((int)$prevOs, 0, ',', '.') }}
      </div>
    </div>

    <div class="rounded-2xl border border-slate-200 bg-white p-4">
      <div class="text-xs text-slate-500">Perubahan (Terakhir vs H-1)</div>
      <div class="text-xl font-extrabold {{ $delta >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
        {{ $delta >= 0 ? '+' : '' }}Rp {{ number_format((int)$delta, 0, ',', '.') }}
      </div>
    </div>
  </div>

  {{-- Chart + Controls --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-4 space-y-4">
    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
      <div>
        <div class="font-bold text-slate-900">Grafik Harian</div>
        <div class="text-xs text-slate-500">
          Catatan: tanggal yang tidak punya snapshot akan tampil <b>putus</b> (bukan 0).
        </div>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        {{-- Mode --}}
        <div class="rounded-xl border border-slate-200 p-1 bg-slate-50 flex items-center gap-1">
          <button type="button" id="btnModeOs"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200">
            OS Total
          </button>
          <button type="button" id="btnModeGrowth"
                  class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
            Growth (Δ H vs H-1)
          </button>
        </div>

        {{-- quick actions --}}
        <button type="button" id="btnSelectAll"
                class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold hover:bg-slate-50">
          Select all
        </button>
        <button type="button" id="btnClearAll"
                class="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold hover:bg-slate-50">
          Clear
        </button>
      </div>
    </div>

    {{-- Toggle staff list --}}
    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-3">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div class="text-sm font-bold text-slate-800">Pilih Staff (garis grafik)</div>
        <input id="staffSearch" type="text" placeholder="Cari nama/role..."
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm w-full md:w-72 bg-white" />
      </div>

      <div id="staffList" class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2 max-h-64 overflow-auto pr-1">
        {{-- diisi via JS --}}
      </div>
    </div>

    <div class="w-full overflow-x-auto">
      <canvas id="osChart" height="120"></canvas>
    </div>
  </div>

  {{-- Debitur jatuh tempo bulan ini --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="font-bold text-slate-900">Debitur Jatuh Tempo – {{ $dueMonthLabel ?? now()->translatedFormat('F Y') }}</div>
      <div class="text-xs text-slate-500 mt-1">
        Sumber: kolom maturity_date hasil import Excel (tgl_jto). Scope mengikuti bawahan TL.
      </div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <th class="text-left px-3 py-2">Jatuh Tempo</th>
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse(($dueThisMonth ?? []) as $r)
            <tr>
              <td class="px-3 py-2 whitespace-nowrap">
                {{ \Carbon\Carbon::parse($r->maturity_date)->format('d/m/Y') }}
              </td>
              <td class="px-3 py-2 font-mono">{{ $r->account_no ?? '-' }}</td>
              <td class="px-3 py-2">{{ $r->customer_name ?? '-' }}</td>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code ?? '-' }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format((int)($r->outstanding ?? 0),0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)($r->dpd ?? 0) }}</td>
              <td class="px-3 py-2 text-right">{{ $r->kolek ?? '-' }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="7" class="px-3 py-6 text-center text-slate-500">
                Belum ada data jatuh tempo bulan ini (atau maturity_date belum terisi dari import).
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  {{-- Migrasi Tunggakan --}}
  <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
    <div class="p-4 border-b border-slate-200">
      <div class="text-lg font-extrabold text-slate-900">
        Migrasi Tunggakan (bulan lalu 0 → posisi terakhir &gt; 0)
      </div>
      <div class="text-sm text-slate-500 mt-1">
        Pembanding: snapshot bulan lalu <b>{{ \Carbon\Carbon::parse($prevSnapMonth)->format('Y-m-d') }}</b>
        → posisi terakhir <b>{{ $latestPosDate }}</b>. Scope mengikuti bawahan TL.
      </div>
    </div>

    <div class="p-4 overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50">
          <tr class="text-slate-700">
            <!-- <th class="text-left px-3 py-2">Mulai Tunggak</th> -->
            <th class="text-left px-3 py-2">No Rek</th>
            <th class="text-left px-3 py-2">Nama Debitur</th>
            <th class="text-left px-3 py-2">AO</th>
            <th class="text-right px-3 py-2">OS</th>
            <th class="text-right px-3 py-2">FT Pokok</th>
            <th class="text-right px-3 py-2">FT Bunga</th>
            <th class="text-right px-3 py-2">DPD</th>
            <th class="text-right px-3 py-2">Kolek</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-slate-200">
          @forelse($migrasiTunggakan as $r)
            <tr>
              <!-- <td class="px-3 py-2 text-slate-500">-</td> -->
              <td class="px-3 py-2 font-mono">{{ $r->account_no }}</td>
              <td class="px-3 py-2">{{ $r->customer_name }}</td>
              <td class="px-3 py-2 font-mono">{{ $r->ao_code }}</td>
              <td class="px-3 py-2 text-right">Rp {{ number_format((int)$r->os,0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((int)$r->ft_pokok,0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ number_format((int)$r->ft_bunga,0,',','.') }}</td>
              <td class="px-3 py-2 text-right">{{ (int)$r->dpd }}</td>
              <td class="px-3 py-2 text-right">{{ (int)$r->kolek }}</td>
            </tr>
          @empty
            <tr>
              <td colspan="9" class="px-3 py-6 text-center text-slate-500">
                Belum ada data migrasi tunggakan untuk periode ini.
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
      <div class="mt-4 flex flex-col md:flex-row md:items-center md:justify-between gap-3">
        <div class="text-xs text-slate-500">
          Menampilkan
          <b>{{ $migrasiTunggakan->firstItem() ?? 0 }}</b>
          –
          <b>{{ $migrasiTunggakan->lastItem() ?? 0 }}</b>
          dari <b>{{ $migrasiTunggakan->total() }}</b> data
        </div>

        <div class="flex items-center gap-2">
          {{-- optional: dropdown per_page --}}
          <form method="GET" class="flex items-center gap-2">
            {{-- keep existing query --}}
            @foreach(request()->except(['page','per_page']) as $k => $v)
              <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endforeach

            <label class="text-xs text-slate-500">Per halaman</label>
            <select name="per_page"
                    class="rounded-xl border border-slate-300 px-2 py-1 text-xs bg-white"
                    onchange="this.form.submit()">
              @foreach([10,25,50,100,200] as $n)
                <option value="{{ $n }}" {{ (int)request('per_page',25)===$n ? 'selected' : '' }}>{{ $n }}</option>
              @endforeach
            </select>
          </form>
        </div>
      </div>

      <div class="mt-3">
        {{ $migrasiTunggakan->onEachSide(1)->links() }}
      </div>

    </div>

    <!-- <div class="px-4 pb-4 text-xs text-slate-500">
      Catatan: “Mulai tunggak” belum bisa dihitung akurat karena data harian per rekening belum disimpan (loan_accounts di-upsert).
      Kalau mau akurat, kita buat tabel snapshot harian per rekening.
    </div> -->
  </div>

</div>

{{-- Chart.js CDN --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  const labels = @json($labels);
  const rawDatasets = @json($datasets); // [{key,label,data:[...]}]
  const staff = @json($staff); // [{id,name,level,ao_code,os_latest}]

  // staffMap[key=ao_code] => staff info
  const staffMap = new Map((staff || []).map(s => [String(s.ao_code), s]));

  // ===== Helpers =====
  const fmtRp = (v) => 'Rp ' + Number(v || 0).toLocaleString('id-ID');
  const isNil = (v) => v === null || typeof v === 'undefined';

  // growth: delta hari ini vs hari kemarin (null tetap null)
  function toGrowthSeries(arr) {
    const out = [];
    for (let i = 0; i < arr.length; i++) {
      const cur = arr[i];
      const prev = i > 0 ? arr[i-1] : null;

      if (isNil(cur) || isNil(prev)) out.push(null);
      else out.push(Number(cur) - Number(prev));
    }
    return out;
  }

  // ===== State =====
  let mode = 'os'; // 'os' | 'growth'
  const enabled = new Set(rawDatasets.map(d => d.key)); // default: all ON

  // ===== Build staff toggle UI =====
  const staffListEl = document.getElementById('staffList');
  const staffSearchEl = document.getElementById('staffSearch');

  function renderStaffList(filterText = '') {
    const q = (filterText || '').trim().toLowerCase();

    const items = rawDatasets.filter(ds => {
      const s = (ds.label || '').toLowerCase();   // label = "Nama (Role)"
      const k = (ds.key || '').toLowerCase();     // ao_code
      const st = staffMap.get(String(ds.key));
      const extra = st ? `${st.name} ${st.level}`.toLowerCase() : '';
      return q === '' || s.includes(q) || extra.includes(q) || k.includes(q);
    });

    staffListEl.innerHTML = items.map(ds => {
      const checked = enabled.has(ds.key) ? 'checked' : '';
      const st = staffMap.get(String(ds.key)) || {};
      const osLatest = (typeof st.os_latest !== 'undefined') ? Number(st.os_latest) : 0;

      return `
        <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2 cursor-pointer hover:bg-slate-50">
          <input type="checkbox" class="staffToggle" data-key="${ds.key}" ${checked} />
          <div class="min-w-0">
            <div class="text-sm font-semibold text-slate-900 truncate">${ds.label}</div>
            <div class="text-xs text-slate-500">
              OS Terakhir: <span class="font-semibold text-slate-700">${fmtRp(osLatest)}</span>
            </div>
          </div>
        </label>
      `;
    }).join('');
  }

  staffListEl.addEventListener('change', (e) => {
    const el = e.target;
    if (!el.classList.contains('staffToggle')) return;

    const key = el.getAttribute('data-key');
    if (el.checked) enabled.add(key);
    else enabled.delete(key);

    rebuildChartDatasets();
  });

  staffSearchEl.addEventListener('input', (e) => renderStaffList(e.target.value));

  document.getElementById('btnSelectAll').addEventListener('click', () => {
    rawDatasets.forEach(d => enabled.add(d.key));
    renderStaffList(staffSearchEl.value);
    rebuildChartDatasets();
  });

  document.getElementById('btnClearAll').addEventListener('click', () => {
    enabled.clear();
    renderStaffList(staffSearchEl.value);
    rebuildChartDatasets();
  });

  // ===== Mode buttons =====
  const btnModeOs = document.getElementById('btnModeOs');
  const btnModeGrowth = document.getElementById('btnModeGrowth');

  function setMode(next) {
    mode = next;
    if (mode === 'os') {
      btnModeOs.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
      btnModeGrowth.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
    } else {
      btnModeOs.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
      btnModeGrowth.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
    }
    rebuildChartDatasets();
  }

  btnModeOs.addEventListener('click', () => setMode('os'));
  btnModeGrowth.addEventListener('click', () => setMode('growth'));

  // ===== Chart init =====
  const ctx = document.getElementById('osChart').getContext('2d');

  const chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: []
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: function(ctx){
              const v = ctx.raw;
              if (isNil(v)) return `${ctx.dataset.label}: (no data)`;

              if (mode === 'growth') {
                const sign = v >= 0 ? '+' : '';
                return `${ctx.dataset.label}: ${sign}${fmtRp(v)}`;
              }
              return `${ctx.dataset.label}: ${fmtRp(v)}`;
            }
          }
        }
      },
      scales: {
        y: {
          ticks: {
            callback: (v) => mode === 'growth'
              ? (Number(v) >= 0 ? '+' : '') + 'Rp ' + Number(v).toLocaleString('id-ID')
              : 'Rp ' + Number(v).toLocaleString('id-ID')
          }
        }
      }
    }
  });

  function buildChartDatasets() {
    const active = rawDatasets.filter(d => enabled.has(d.key));

    return active.map(ds => {
      const data = (mode === 'growth') ? toGrowthSeries(ds.data) : ds.data;

      const nonNullCount = data.reduce((acc, v) => acc + (v !== null && typeof v !== 'undefined' ? 1 : 0), 0);

      return {
        label: ds.label,
        data,
        spanGaps: false,
        tension: 0.2,
        pointRadius: nonNullCount <= 1 ? 4 : 0,
        pointHoverRadius: nonNullCount <= 1 ? 6 : 4,
        borderWidth: 2
      };
    });
  }

  function rebuildChartDatasets() {
    chart.data.datasets = buildChartDatasets();
    chart.update();
  }

  // first render
  renderStaffList('');
  rebuildChartDatasets();
</script>

@endsection
