@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const trendLabels = @json($trendLabels ?? []);
    const trendTotalOs = @json($trendTotalOs ?? []);
    const trendNplPct = @json($trendNplPct ?? []);
    const trendKolek = @json($trendKolek ?? []);
    const trendFt = @json($trendFt ?? []);
    const trendTargetActual = @json($trendTargetActual ?? ['target_ytd' => [], 'actual_ytd' => []]);

    const moneyTick = (value) => {
        const n = Number(value || 0);
        if (Math.abs(n) >= 1_000_000_000_000) return (n / 1_000_000_000_000).toFixed(1) + ' T';
        if (Math.abs(n) >= 1_000_000_000) return (n / 1_000_000_000).toFixed(1) + ' M';
        if (Math.abs(n) >= 1_000_000) return (n / 1_000_000).toFixed(1) + ' Jt';
        return n.toLocaleString('id-ID');
    };

    const portfolioComposition = {{ \Illuminate\Support\Js::from($portfolioComposition ?? ['labels' => [], 'values' => []]) }};

    const baseOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: {
                display: true,
                labels: {
                    boxWidth: 14,
                    usePointStyle: false,
                }
            }
        }
    };

    const elTotalOs = document.getElementById('chartTotalOs');
    if (elTotalOs) {
        new Chart(elTotalOs, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: 'Total OS',
                    data: trendTotalOs,
                    tension: 0.35,
                    borderWidth: 2,
                    fill: false
                }]
            },
            options: {
                ...baseOptions,
                scales: {
                    y: {
                        ticks: { callback: moneyTick }
                    }
                }
            }
        });
    }

    const elNplPct = document.getElementById('chartNplPct');
    if (elNplPct) {
        new Chart(elNplPct, {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [
                    {
                        label: 'NPL %',
                        data: trendNplPct,
                        borderColor: '#dc2626',
                        backgroundColor: '#dc2626',
                        pointBackgroundColor: '#dc2626',
                        pointBorderColor: '#ffffff',
                        tension: 0.35,
                        borderWidth: 3,
                        fill: {
                            target: 'origin',
                            above: 'rgba(220,38,38,0.08)',
                        }
                    },
                    {
                        label: 'Target NPL',
                        data: trendLabels.map(() => 5),
                        borderColor: '#f59e0b',
                        borderDash: [6, 6],
                        pointRadius: 0,
                        borderWidth: 2,
                        fill: false
                    }
                ]
            },
            options: {
                ...baseOptions,
                scales: {
                    y: {
                        ticks: {
                            callback: (value) => Number(value || 0).toLocaleString('id-ID') + '%'
                        }
                    }
                }
            }
        });
    }

    const elTargetActual = document.getElementById('chartTargetActual');
    if (elTargetActual) {
        new Chart(elTargetActual, {
            type: 'bar',
            data: {
                labels: trendLabels,
                datasets: [
                    {
                        label: 'Target YTD',
                        data: trendTargetActual.target_ytd ?? [],
                        borderColor: '#94a3b8',
                        backgroundColor: 'rgba(148,163,184,0.55)',
                        borderWidth: 1
                    },
                    {
                        label: 'Aktual YTD',
                        data: trendTargetActual.actual_ytd ?? [],
                        borderColor: '#0f172a',
                        backgroundColor: 'rgba(15,23,42,0.75)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                ...baseOptions,
                scales: {
                    y: {
                        ticks: { callback: moneyTick }
                    }
                }
            }
        });
    }

    const elKolek = document.getElementById('chartKolek');
    if (elKolek) {
        new Chart(elKolek, {
            type: 'bar',
            data: {
                labels: trendLabels,
                datasets: [
                    { label: 'L', data: trendKolek.l_os ?? [], stack: 'kolek' },
                    { label: 'DPK', data: trendKolek.dpk_os ?? [], stack: 'kolek' },
                    { label: 'KL', data: trendKolek.kl_os ?? [], stack: 'kolek' },
                    { label: 'D', data: trendKolek.d_os ?? [], stack: 'kolek' },
                    { label: 'M', data: trendKolek.m_os ?? [], stack: 'kolek' },
                ]
            },
            options: {
                ...baseOptions,
                scales: {
                    x: { stacked: true },
                    y: {
                        stacked: true,
                        ticks: { callback: moneyTick }
                    }
                }
            }
        });
    }

    const elFt = document.getElementById('chartFt');
    if (elFt) {
        new Chart(elFt, {
            type: 'bar',
            data: {
                labels: trendLabels,
                datasets: [
                    { label: 'FT0', data: trendFt.ft0_os ?? [], stack: 'ft' },
                    { label: 'FT1', data: trendFt.ft1_os ?? [], stack: 'ft' },
                    { label: 'FT2', data: trendFt.ft2_os ?? [], stack: 'ft' },
                    { label: 'FT3', data: trendFt.ft3_os ?? [], stack: 'ft' },
                ]
            },
            options: {
                ...baseOptions,
                scales: {
                    x: { stacked: true },
                    y: {
                        stacked: true,
                        ticks: { callback: moneyTick }
                    }
                }
            }
        });
    }

    const elPortfolioComposition = document.getElementById('chartPortfolioComposition');
    if (elPortfolioComposition) {
        new Chart(elPortfolioComposition, {
            type: 'doughnut',
            data: {
                labels: portfolioComposition.labels ?? [],
               datasets: [{
                    data: portfolioComposition.values,
                    backgroundColor: [
                        '#60a87d', // L
                        '#facc15', // DPK
                        '#fb923c', // KL
                        '#ef4444', // D
                        '#7f1d1d'  // M
                    ],
                
                    borderColor: '#ffffff',
                    borderWidth: 2,
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12
                        }
                    }
                }
            }
        });
    }
</script>
@endpush