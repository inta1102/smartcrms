<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>

<script>
  const labels = @json($labels ?? []);
  const datasetsByMetric = @json($datasetsByMetric ?? []);

  const isNil = (v) => v === null || typeof v === 'undefined';
  const fmtRp = (v) => 'Rp ' + Number(v || 0).toLocaleString('id-ID');
  const fmtPct = (v) => Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + '%';
  const isPercentMetric = (m) => (m === 'rr' || m === 'pct_lt');

  Chart.register(ChartDataLabels);

  function fmtCompactRp(v){
    const n = Number(v || 0);
    const abs = Math.abs(n);
    if (abs >= 1e12) return (n/1e12).toFixed(2).replace('.',',') + 'T';
    if (abs >= 1e9)  return (n/1e9 ).toFixed(2).replace('.',',') + 'M';
    if (abs >= 1e6)  return (n/1e6 ).toFixed(1).replace('.',',') + 'jt';
    return n.toLocaleString('id-ID');
  }

  function lastIndexNonNull(arr) {
    for (let i = (arr?.length || 0) - 1; i >= 0; i--) {
      if (!isNil(arr[i])) return i;
    }
    return -1;
  }

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

  function anomalyThreshold(metricKey) {
    if (metricKey === 'rr' || metricKey === 'pct_lt') return 2;
    return 500000000;
  }

  function isAnomalyPoint(metricKey, value) {
    if (isNil(value)) return false;
    const thr = anomalyThreshold(metricKey);
    return Math.abs(Number(value)) >= thr;
  }

  let metric = 'os_total';
  let mode = 'value';
  let showAllLines = false;
  let showAllPointLabels = false;
  let showTotalLine = true;

  const isMobile = () => window.matchMedia('(max-width: 639px)').matches;

  function getRawDatasets() {
    return (datasetsByMetric && datasetsByMetric[metric]) ? datasetsByMetric[metric] : [];
  }

  function applyMobileDatasetRules(datasets) {
    if (!isMobile()) return datasets;
    if (showAllLines) return datasets.map(ds => ({ ...ds, hidden: false }));
    const maxLines = isPercentMetric(metric) ? 2 : 3;
    return datasets.map((ds, idx) => ({ ...ds, hidden: idx >= maxLines }));
  }

  function sumAtIndex(metricKey, idx) {
    const sets = (datasetsByMetric && datasetsByMetric[metricKey]) ? datasetsByMetric[metricKey] : [];
    let sum = 0;
    let hasAny = false;
    for (const ds of sets) {
      const v = ds?.data?.[idx];
      if (!isNil(v)) {
        sum += Number(v);
        hasAny = true;
      }
    }
    return hasAny ? sum : null;
  }

  function seriesSum(metricKey) {
    const sets = (datasetsByMetric && datasetsByMetric[metricKey]) ? datasetsByMetric[metricKey] : [];
    const n = labels?.length || 0;
    const out = new Array(n).fill(null);

    for (let i = 0; i < n; i++) {
      let sum = 0;
      let has = false;
      for (const ds of sets) {
        const v = ds?.data?.[i];
        if (!isNil(v)) { sum += Number(v); has = true; }
      }
      out[i] = has ? sum : null;
    }
    return out;
  }

  function topContributorAtIndex(metricKey, idx) {
    const sets = (datasetsByMetric && datasetsByMetric[metricKey]) ? datasetsByMetric[metricKey] : [];
    let best = null;
    for (const ds of sets) {
      const v = ds?.data?.[idx];
      if (isNil(v)) continue;
      if (!best || Number(v) > Number(best.value)) {
        best = { label: ds.label || 'Series', value: Number(v) };
      }
    }
    return best;
  }

  function findLastIndexWithAnyDataForMetric(metricKey) {
    const sets = (datasetsByMetric && datasetsByMetric[metricKey]) ? datasetsByMetric[metricKey] : [];
    const n = labels?.length || 0;
    for (let i = n - 1; i >= 0; i--) {
      let has = false;
      for (const ds of sets) {
        if (!isNil(ds?.data?.[i])) { has = true; break; }
      }
      if (has) return i;
    }
    return null;
  }

  function findLastIndexWithAnyData() {
    const n = labels?.length || 0;
    for (let i = n - 1; i >= 0; i--) {
      const t = sumAtIndex('os_total', i);
      const l0 = sumAtIndex('os_l0', i);
      const lt = sumAtIndex('os_lt', i);
      if (!isNil(t) || !isNil(l0) || !isNil(lt)) return i;
    }
    return null;
  }

  function buildDatasets() {
    const raw = getRawDatasets();
    const lastIdx = findLastIndexWithAnyDataForMetric(metric);
    const top = (!isNil(lastIdx) ? topContributorAtIndex(metric, lastIdx) : null);

    let ds = raw.map((ds) => {
      const base = ds.data || [];
      const data = (mode === 'growth') ? toGrowthSeries(base) : base;
      const isTop = (top && (ds.label === top.label));

      return {
        label: ds.label || 'Series',
        data,
        spanGaps: false,
        tension: 0.2,
        pointBorderWidth: (ctx) => {
          const v = ctx.raw;
          const i = ctx.dataIndex;
          if (top && (ds.label === top.label) && i === lastIdx) return 3;
          if (mode === 'growth' && isAnomalyPoint(metric, v)) return 3;
          return 2;
        },
        pointRadius: (ctx) => {
          const v = ctx.raw;
          const i = ctx.dataIndex;
          if (top && (ds.label === top.label) && i === lastIdx) return (isMobile() ? 5 : 6);
          if (mode === 'growth' && isAnomalyPoint(metric, v)) return (isMobile() ? 4 : 5);
          return (isMobile() ? 2.5 : 3);
        },
        pointHoverRadius: isMobile() ? 4 : 5,
        borderWidth: isTop ? 3 : 2,
      };
    });

    if (showTotalLine) {
      const totalBase = seriesSum(metric);
      const totalData = (mode === 'growth') ? toGrowthSeries(totalBase) : totalBase;
      ds.unshift({
        label: 'TOTAL TL',
        data: totalData,
        spanGaps: false,
        tension: 0.25,
        borderWidth: 3,
        pointRadius: isMobile() ? 3 : 3.5,
        pointHoverRadius: isMobile() ? 5 : 6,
        pointBorderWidth: 2,
      });
    }

    ds = applyMobileDatasetRules(ds);
    return ds;
  }

  function updateKpiStrip() {
    const idx = findLastIndexWithAnyData();
    if (isNil(idx)) {
      document.getElementById('kpiLatestOs').textContent = '-';
      document.getElementById('kpiLatestL0').textContent = '-';
      document.getElementById('kpiLatestLT').textContent = '-';
      document.getElementById('kpiLatestRR').textContent = '-';
      document.getElementById('kpiLatestPctLT').textContent = '-';
      return;
    }

    const os = sumAtIndex('os_total', idx);
    const l0 = sumAtIndex('os_l0', idx);
    const lt = sumAtIndex('os_lt', idx);
    const rr = (!isNil(os) && os > 0 && !isNil(l0)) ? (Number(l0) / Number(os)) * 100 : null;
    const pctlt = (!isNil(os) && os > 0 && !isNil(lt)) ? (Number(lt) / Number(os)) * 100 : null;

    document.getElementById('kpiLatestOs').textContent = isNil(os) ? '-' : fmtRp(os);
    document.getElementById('kpiLatestL0').textContent = isNil(l0) ? '-' : fmtRp(l0);
    document.getElementById('kpiLatestLT').textContent = isNil(lt) ? '-' : fmtRp(lt);
    document.getElementById('kpiLatestRR').textContent = isNil(rr) ? '-' : fmtPct(rr);
    document.getElementById('kpiLatestPctLT').textContent = isNil(pctlt) ? '-' : fmtPct(pctlt);
  }

  const canvas = document.getElementById('osChart');
  const chart = new Chart(canvas.getContext('2d'), {
    type: 'line',
    data: { labels, datasets: buildDatasets() },
    plugins: [ChartDataLabels],
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          display: true,
          position: 'bottom',
          labels: {
            usePointStyle: true,
            pointStyle: 'line',
            boxWidth: isMobile() ? 10 : 12,
            boxHeight: isMobile() ? 10 : 12,
            padding: isMobile() ? 10 : 14,
            font: { size: isMobile() ? 10 : 12 },
          },
        },
        tooltip: {
          callbacks: {
            label: function (ctx) {
              const v = ctx.raw;
              if (isNil(v)) return `${ctx.dataset.label}: (no data)`;
              const pct = isPercentMetric(metric);
              if (mode === 'growth') {
                const sign = Number(v) >= 0 ? '+' : '';
                return `${ctx.dataset.label}: ${sign}${pct ? fmtPct(v) : fmtRp(v)}`;
              }
              return `${ctx.dataset.label}: ${pct ? fmtPct(v) : fmtRp(v)}`;
            },
          },
        },
        datalabels: {
          anchor: 'end',
          align: 'top',
          offset: 6,
          clamp: true,
          clip: false,
          font: { size: isMobile() ? 9 : 10, weight: '600' },
          display: function(ctx){
            const v = ctx.dataset?.data?.[ctx.dataIndex];
            if (isNil(v)) return false;
            if (showAllPointLabels) {
              if (isMobile()) return (ctx.dataIndex % 2 === 0);
              return true;
            }
            const data = ctx.dataset.data || [];
            const li = lastIndexNonNull(data);
            return li >= 0 && ctx.dataIndex === li;
          },
          formatter: function (value) {
            if (isNil(value)) return '';
            const pct = isPercentMetric(metric);
            if (pct) return Number(value).toLocaleString('id-ID', { maximumFractionDigits: 2 }) + '%';
            return fmtCompactRp(value);
          },
        },
      },
      scales: {
        x: {
          ticks: {
            autoSkip: true,
            maxTicksLimit: isMobile() ? 6 : 14,
            maxRotation: isMobile() ? 45 : 0,
            minRotation: isMobile() ? 45 : 0,
            font: { size: isMobile() ? 10 : 11 },
          },
          grid: { display: !isMobile() },
        },
        y: {
          ticks: {
            font: { size: isMobile() ? 10 : 11 },
            callback: (v) => {
              if (isMobile()) return '';
              const pct = isPercentMetric(metric);
              if (mode === 'growth') {
                const sign = Number(v) >= 0 ? '+' : '';
                return sign + (pct ? fmtPct(v) : ('Rp ' + Number(v).toLocaleString('id-ID')));
              }
              return pct ? fmtPct(v) : ('Rp ' + Number(v).toLocaleString('id-ID'));
            },
          },
        },
      },
    },
  });

  function repaintModeButtons() {
    const btnValue = document.getElementById('btnModeValue');
    const btnGrowth = document.getElementById('btnModeGrowth');
    if (mode === 'value') {
      btnValue.className  = 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
      btnGrowth.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
    } else {
      btnValue.className  = 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
      btnGrowth.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
    }
  }

  function repaintShowAllButton() {
    const btn = document.getElementById('btnShowAllLines');
    if (!btn) return;
    btn.textContent = showAllLines ? 'Tampilkan ringkas' : 'Tampilkan semua garis';
    btn.className = showAllLines
      ? 'w-full rounded-xl border border-slate-200 bg-slate-900 px-3 py-2 text-xs font-semibold text-white'
      : 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-800';
  }

  function repaintMetricButtons() {
    const map = {
      os_total: 'btnMetricTotal',
      os_l0: 'btnMetricL0',
      os_lt: 'btnMetricLT',
      rr: 'btnMetricRR',
      pct_lt: 'btnMetricPctLT',
    };
    Object.entries(map).forEach(([m, id]) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.className = (m === metric)
        ? 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200'
        : 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
    });
  }

  function repaintLabelButtons() {
    const btnLast = document.getElementById('btnLabelsLastOnly');
    const btnAll  = document.getElementById('btnLabelsAll');
    if (!btnLast || !btnAll) return;
    if (!showAllPointLabels) {
      btnLast.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
      btnAll.className  = 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
    } else {
      btnLast.className = 'px-3 py-1.5 text-xs font-semibold rounded-lg text-slate-700';
      btnAll.className  = 'px-3 py-1.5 text-xs font-semibold rounded-lg bg-white shadow-sm border border-slate-200';
    }
  }

  function refreshChart() {
    chart.data.datasets = buildDatasets();
    chart.update();
    updateKpiStrip();
  }

  document.getElementById('btnModeValue')?.addEventListener('click', () => {
    mode = 'value';
    repaintModeButtons();
    refreshChart();
  });

  document.getElementById('btnModeGrowth')?.addEventListener('click', () => {
    mode = 'growth';
    repaintModeButtons();
    refreshChart();
  });

  document.querySelectorAll('[data-metric]')?.forEach(btn => {
    btn.addEventListener('click', () => {
      metric = btn.getAttribute('data-metric');
      if (isMobile()) { showAllLines = false; repaintShowAllButton(); }
      repaintMetricButtons();
      refreshChart();
    });
  });

  document.getElementById('btnShowAllLines')?.addEventListener('click', () => {
    showAllLines = !showAllLines;
    repaintShowAllButton();
    refreshChart();
  });

  document.getElementById('btnLabelsLastOnly')?.addEventListener('click', () => {
    showAllPointLabels = false;
    repaintLabelButtons();
    refreshChart();
  });

  document.getElementById('btnLabelsAll')?.addEventListener('click', () => {
    showAllPointLabels = true;
    repaintLabelButtons();
    refreshChart();
  });

  repaintMetricButtons();
  repaintModeButtons();
  repaintShowAllButton();
  repaintLabelButtons();
  updateKpiStrip();

  let __resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(__resizeTimer);
    __resizeTimer = setTimeout(() => refreshChart(), 200);
  });

  const toggleUrl = @json(route('ro_visits.toggle'));
  const csrf = @json(csrf_token());

  function formatPlanDate(planDateYmd) {
    if (!planDateYmd) return '-';
    const d = new Date(planDateYmd + 'T00:00:00');
    const dd = String(d.getDate()).padStart(2,'0');
    const mm = String(d.getMonth()+1).padStart(2,'0');
    const yy = d.getFullYear();
    return `${dd}/${mm}/${yy}`;
  }

  function setPlanUi(accountNo, checked, locked, planDateYmd) {
    document.querySelectorAll(`.btnPlanVisit[data-acc="${CSS.escape(accountNo)}"]`).forEach(btn => {
      btn.dataset.checked = checked ? '1' : '0';
      btn.disabled = !!locked;
      if (checked) {
        btn.textContent = 'Unplan';
        btn.className = 'btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border bg-slate-900 text-white border-slate-900';
      } else {
        btn.textContent = 'Plan';
        btn.className = 'btnPlanVisit inline-flex items-center rounded-full px-3 py-2 text-xs font-semibold border bg-white text-slate-800 border-slate-200';
      }
      if (locked) {
        btn.className += ' opacity-60 cursor-not-allowed';
      }
    });

    const planText = formatPlanDate(planDateYmd);
    document.querySelectorAll(`.ro-plan-date[data-account="${CSS.escape(accountNo)}"]`).forEach(el => {
      el.textContent = planText;
    });
  }

  async function postToggle(accountNo, aoCode, checked) {
    const res = await fetch(toggleUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrf,
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        account_no: accountNo,
        ao_code: aoCode || null,
        checked: !!checked,
        source: 'dashboard',
      }),
    });

    if (!res.ok) {
      const txt = await res.text();
      throw new Error(txt || 'Request failed');
    }
    return await res.json();
  }

  function bindPlanButtons() {
    document.querySelectorAll('.btnPlanVisit').forEach(btn => {
      btn.addEventListener('click', async () => {
        const accountNo = btn.getAttribute('data-acc') || '';
        const aoCode = btn.getAttribute('data-ao') || '';
        const currentlyChecked = (btn.dataset.checked === '1');
        const nextChecked = !currentlyChecked;
        btn.disabled = true;
        try {
          const json = await postToggle(accountNo, aoCode, nextChecked);
          setPlanUi(accountNo, json.checked, json.locked, json.plan_date);
        } catch (err) {
          btn.disabled = false;
          alert('Gagal update plan visit. Coba refresh halaman.\n\n' + (err?.message || err));
        }
      });
    });
  }

  bindPlanButtons();
</script>