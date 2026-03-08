<div class="rounded-2xl border border-slate-200 bg-white p-4 space-y-4">
  <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3">
    <div>
      <div class="font-bold text-slate-900">Grafik Harian</div>
      <div class="text-xs text-slate-500">
        Tanggal tanpa snapshot akan tampil <b>putus</b> (bukan 0).
      </div>

      <div class="mt-3 flex flex-wrap gap-2">
        <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
          <span class="text-slate-500">Latest OS:</span>
          <b id="kpiLatestOs" class="text-slate-900">-</b>
        </span>
        <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
          <span class="text-slate-500">Latest L0:</span>
          <b id="kpiLatestL0" class="text-slate-900">-</b>
        </span>
        <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
          <span class="text-slate-500">Latest LT:</span>
          <b id="kpiLatestLT" class="text-slate-900">-</b>
        </span>
        <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
          <span class="text-slate-500">RR:</span>
          <b id="kpiLatestRR" class="text-slate-900">-</b>
        </span>
        <span class="px-3 py-1 rounded-xl bg-slate-50 border border-slate-200 text-xs">
          <span class="text-slate-500">%LT:</span>
          <b id="kpiLatestPctLT" class="text-slate-900">-</b>
        </span>
      </div>
    </div>

    <div class="flex items-center gap-2 flex-wrap justify-end">
      <div class="rounded-xl border border-slate-200 p-1 bg-slate-50 flex items-center gap-1">
        <button type="button" data-metric="os_total" id="btnMetricTotal"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200">
          OS Total
        </button>
        <button type="button" data-metric="os_l0" id="btnMetricL0"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
          OS L0
        </button>
        <button type="button" data-metric="os_lt" id="btnMetricLT"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
          OS LT
        </button>
        <button type="button" data-metric="rr" id="btnMetricRR"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
          RR (% L0)
        </button>
        <button type="button" data-metric="pct_lt" id="btnMetricPctLT"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
          % LT
        </button>
      </div>

      <div class="rounded-xl border border-slate-200 p-1 bg-slate-50 flex items-center gap-1">
        <button type="button" id="btnModeValue"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200">
          Value
        </button>
        <button type="button" id="btnModeGrowth"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
          Growth (Δ H vs H-1)
        </button>
      </div>

      <div class="rounded-xl border border-slate-200 p-1 bg-slate-50 flex items-center gap-1">
        <button type="button" id="btnLabelsLastOnly"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200">
          Label: Last
        </button>
        <button type="button" id="btnLabelsAll"
                class="px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700">
          Label: Semua
        </button>
      </div>

      <div class="w-full sm:hidden">
        <button type="button" id="btnShowAllLines"
                class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-800">
          Tampilkan semua garis
        </button>
        <div class="mt-1 text-[11px] text-slate-500">
          Mode ringkas membantu grafik lebih kebaca di HP.
        </div>
      </div>
    </div>
  </div>

  <div class="w-full">
    <div class="relative w-full h-[260px] sm:h-[360px] md:h-[420px]">
      <canvas id="osChart" class="w-full h-full"></canvas>
    </div>

    <div class="mt-2 text-[11px] text-slate-500 sm:hidden">
      Tips: geser layar ke samping untuk melihat detail legend & garis.
    </div>
  </div>
</div>