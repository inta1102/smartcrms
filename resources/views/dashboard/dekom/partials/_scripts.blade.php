@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const trendLabels = @json($trendLabels ?? []);
    const trendTotalOs = @json($trendTotalOs ?? []);
    const trendNplPct = @json($trendNplPct ?? []);
    const trendNplTarget = @json($trendNplTarget ?? []);
    const trendKolek = @json($trendKolek ?? []);
    const trendFt = @json($trendFt ?? []);
    const trendTargetActual = @json($trendTargetActual ?? ['target_ytd' => [], 'actual_ytd' => []]);
    const portfolioComposition = {{ \Illuminate\Support\Js::from($portfolioComposition ?? ['labels' => [], 'values' => []]) }};
    const targetAchievement = @json($targetAchievement ?? []);

    function compactMoney(value) {
        const n = Number(value || 0);
        const abs = Math.abs(n);

        if (abs >= 1_000_000_000_000) {
            const v = n / 1_000_000_000_000;
            return (Number.isInteger(v) ? v.toFixed(0) : v.toFixed(1)) + 'T';
        }

        // M = Miliar
        if (abs >= 1_000_000_000) {
            const v = n / 1_000_000_000;
            return (Number.isInteger(v) ? v.toFixed(0) : v.toFixed(1)) + 'M';
        }

        if (abs >= 1_000_000) {
            const v = n / 1_000_000;
            return (Number.isInteger(v) ? v.toFixed(0) : v.toFixed(1)) + 'Jt';
        }

        if (abs >= 1_000) {
            const v = n / 1_000;
            return (Number.isInteger(v) ? v.toFixed(0) : v.toFixed(1)) + 'Rb';
        }

        return n.toLocaleString('id-ID');
    }

    function fullMoney(value) {
        return Number(value || 0).toLocaleString('id-ID');
    }

    function percentTick(value) {
        return Number(value || 0).toLocaleString('id-ID') + '%';
    }

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
                plugins: {
                    ...baseOptions.plugins,
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${compactMoney(context.raw)} (${fullMoney(context.raw)})`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) {
                                return compactMoney(value);
                            }
                        }
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
                        data: trendNplTarget ?? [],
                        borderColor: '#f59e0b',
                        backgroundColor: '#f59e0b',
                        borderDash: [6, 6],
                        pointRadius: 3,
                        pointHoverRadius: 4,
                        borderWidth: 2,
                        fill: false
                    }
                ]
            },
            options: {
                ...baseOptions,
                plugins: {
                    ...baseOptions.plugins,
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${Number(context.raw || 0).toLocaleString('id-ID')}%`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        min: 0,
                        max: 30,
                        ticks: {
                            callback: percentTick
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
                plugins: {
                    ...baseOptions.plugins,
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${compactMoney(context.raw)} (${fullMoney(context.raw)})`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) {
                                return compactMoney(value);
                            }
                        }
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
                plugins: {
                    ...baseOptions.plugins,
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${compactMoney(context.raw)} (${fullMoney(context.raw)})`;
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true },
                    y: {
                        stacked: true,
                        ticks: {
                            callback: function(value) {
                                return compactMoney(value);
                            }
                        }
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
                plugins: {
                    ...baseOptions.plugins,
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${compactMoney(context.raw)} (${fullMoney(context.raw)})`;
                            }
                        }
                    }
                },
                scales: {
                    x: { stacked: true },
                    y: {
                        stacked: true,
                        ticks: {
                            callback: function(value) {
                                return compactMoney(value);
                            }
                        }
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
                    data: portfolioComposition.values ?? [],
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
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const val = context.raw || 0;
                                return `${label}: ${compactMoney(val)} (${fullMoney(val)})`;
                            }
                        }
                    }
                }
            }
        });
    }

    function buildBarLineChart(el, labels, actual, target, actualLabel, targetLabel, valueType = 'money') {
        const canvas = document.getElementById(el);
        if (!canvas) return;

        const isPercent = valueType === 'percent';

        new Chart(canvas, {
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'bar',
                        label: actualLabel,
                        data: actual,
                        borderWidth: 1,
                        backgroundColor: 'rgba(96, 165, 250, 0.55)',
                        borderColor: '#60a5fa',
                    },
                    {
                        type: 'line',
                        label: targetLabel,
                        data: target,
                        tension: 0.25,
                        fill: false,
                        borderWidth: 2,
                        backgroundColor: '#fb7185',
                        borderColor: '#fb7185',
                        pointRadius: 4,
                        pointHoverRadius: 5,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top' },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                if (isPercent) {
                                    return `${context.dataset.label}: ${Number(context.raw || 0).toLocaleString('id-ID')}%`;
                                }

                                return `${context.dataset.label}: ${compactMoney(context.raw)} (${fullMoney(context.raw)})`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return isPercent ? percentTick(value) : compactMoney(value);
                            }
                        }
                    }
                }
            }
        });
    }

    buildBarLineChart(
        'chartDisbursementVsTarget',
        targetAchievement.labels ?? [],
        targetAchievement.disbursement_actual ?? [],
        targetAchievement.disbursement_target ?? [],
        'Realisasi Pencairan',
        'Target Pencairan',
        'money'
    );

    buildBarLineChart(
        'chartOsVsTarget',
        targetAchievement.labels ?? [],
        targetAchievement.os_actual ?? [],
        targetAchievement.os_target ?? [],
        'Aktual OS',
        'Target OS',
        'money'
    );

    buildBarLineChart(
        'chartNplVsTarget',
        targetAchievement.labels ?? [],
        targetAchievement.npl_actual ?? [],
        targetAchievement.npl_target ?? [],
        'Aktual NPL %',
        'Target NPL %',
        'percent'
    );
</script>
@endpush