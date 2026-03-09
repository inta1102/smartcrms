<?php

namespace App\Http\Controllers\Kpi;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TlfeOsDailyDashboardController extends Controller
{
    public function index(Request $request)
    {
        $me = auth()->user();
        abort_unless($me, 403);

        /*
         * ============================================================
         * 0) RESOLVE SCOPE TLFE -> FE STAFF
         * ============================================================
         */
        $todayRef = now()->startOfDay();
        $scopeMembers = $this->resolveTlfeScopeMembers($me, $todayRef);

        \Log::info('TLFE scope debug', [
            'leader_id' => $me->id,
            'leader_name' => $me->name,
            'leader_ao' => $me->ao_code,
            'scope_count' => $scopeMembers->count(),
            'scope_members' => $scopeMembers->values()->all(),
        ]);

        // fallback aman: kalau mapping org belum ada / belum terbaca, minimal pakai diri sendiri bila punya ao_code
        abort_if($scopeMembers->isEmpty(), 403, 'Scope FE untuk TLFE tidak ditemukan / mapping org_assignments kosong.');

        $scopeAoCodes = $scopeMembers
            ->pluck('ao_code')
            ->map(fn ($x) => $this->normalizeAoCode($x))
            ->filter()
            ->unique()
            ->values()
            ->all();

        abort_unless(!empty($scopeAoCodes), 403, 'AO code FE dalam scope TLFE tidak ditemukan.');

        $scopeUserIds = $scopeMembers
            ->pluck('user_id')
            ->map(fn ($x) => (int)$x)
            ->filter(fn ($x) => $x > 0)
            ->unique()
            ->values()
            ->all();

        /*
         * ============================================================
         * 1) FILTER PILIH FE / AO
         * ============================================================
         */
        $selectedAoCode = $this->normalizeAoCode($request->input('ao_code'));
        $selectedFeUserId = (int)$request->input('fe_user_id', 0);

        if (!$selectedAoCode && $selectedFeUserId > 0) {
            $found = $scopeMembers->firstWhere('user_id', $selectedFeUserId);
            if ($found) {
                $selectedAoCode = $this->normalizeAoCode($found['ao_code'] ?? null);
            }
        }

        if ($selectedAoCode && !in_array($selectedAoCode, $scopeAoCodes, true)) {
            $selectedAoCode = null;
            $selectedFeUserId = 0;
        }

        if ($selectedAoCode) {
            $activeAoCodes = [$selectedAoCode];

            $selectedMember = $scopeMembers->first(function ($m) use ($selectedAoCode) {
                return $this->normalizeAoCode($m['ao_code'] ?? null) === $selectedAoCode;
            });

            $selectedFeUserId = (int)($selectedMember['user_id'] ?? 0);
            $selectedFeName   = (string)($selectedMember['name'] ?? $selectedAoCode);
            $scopeMode        = 'single';
            $scopeLabel       = 'FE: ' . $selectedFeName;
        } else {
            $activeAoCodes = $scopeAoCodes;
            $selectedFeName = null;
            $scopeMode      = 'all';
            $scopeLabel     = 'Scope TLFE';
        }

        /*
         * ============================================================
         * 2) RANGE DEFAULT
         * - from: akhir bulan lalu
         * - to  : posisi terakhir yang ada di kpi_os_daily_aos
         * ============================================================
         */
        $latestInKpi = DB::table('kpi_os_daily_aos')->max('position_date');
        $latestInKpi = $latestInKpi ? Carbon::parse($latestInKpi)->startOfDay() : now()->startOfDay();

        $lastMonthEndC = Carbon::now()->subMonthNoOverflow()->endOfMonth()->startOfDay();

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : $lastMonthEndC->copy()->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->startOfDay()
            : $latestInKpi->copy()->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->startOfDay()];
        }

        $mode = $request->input('mode', 'mtd'); // mtd | daily
        $mode = in_array($mode, ['mtd', 'daily', 'h'], true) ? $mode : 'mtd';
        if ($mode === 'h') {
            $mode = 'daily';
        }

        /*
         * ============================================================
         * 3) LABELS tanggal lengkap
         * ============================================================
         */
        $labels = [];
        $cursor = $from->copy()->startOfDay();
        $end    = $to->copy()->startOfDay();
        while ($cursor->lte($end)) {
            $labels[] = $cursor->toDateString();
            $cursor->addDay();
        }

        $latestPosDateFallback = count($labels)
            ? $labels[count($labels) - 1]
            : $latestInKpi->toDateString();

        $prevSnapMonth = Carbon::parse($latestPosDateFallback)
            ->subMonthNoOverflow()
            ->startOfMonth()
            ->toDateString();

        /*
         * ============================================================
         * 4) DATA HARIAN KPI - SELURUH SCOPE
         * ============================================================
         */
        \Log::info('TLFE chart scope debug', [
            'scopeMode'      => $scopeMode,
            'selectedAoCode' => $selectedAoCode,
            'activeAoCodes'  => $activeAoCodes,
            'scopeMembers'   => $scopeMembers->values()->all(),
            'from'           => $from->toDateString(),
            'to'             => $to->toDateString(),
        ]);

        $rows = DB::table('kpi_os_daily_aos')
            ->selectRaw("
                DATE(position_date) as d,
                LPAD(TRIM(ao_code),6,'0') as ao_code,
                ROUND(SUM(os_total))       as os_total,
                ROUND(SUM(os_l0))          as os_l0,
                ROUND(SUM(os_lt))          as os_lt,
                ROUND(SUM(os_dpk))         as os_dpk,
                ROUND(SUM(os_potensi))     as os_potensi,
                ROUND(SUM(os_kl))          as os_kl
            ")
            ->whereDate('position_date', '>=', $from->toDateString())
            ->whereDate('position_date', '<=', $to->toDateString())
            ->where(function ($q) use ($scopeAoCodes) {
                $this->applyAoScope($q, 'ao_code', $scopeAoCodes);
            })
            ->groupBy('d', 'ao_code')
            ->orderBy('d')
            ->orderBy('ao_code')
            ->get();

        $scopeByDate = [];
        $staffByDate = [];

        \Log::info('TLFE raw chart rows', [
            'row_count' => $rows->count(),
            'rows' => $rows->map(function ($r) {
                return [
                    'd'          => $r->d,
                    'ao_code'    => $r->ao_code,
                    'os_total'   => $r->os_total,
                    'os_l0'      => $r->os_l0,
                    'os_lt'      => $r->os_lt,
                    'os_dpk'     => $r->os_dpk,
                    'os_potensi' => $r->os_potensi,
                    'os_kl'      => $r->os_kl,
                ];
            })->values()->all(),
        ]);

        foreach ($rows as $r) {
            $ao = $this->normalizeAoCode($r->ao_code ?? null);
            if (!$ao) {
                continue;
            }

            $osTotal    = (int)($r->os_total ?? 0);
            $osL0       = (int)($r->os_l0 ?? 0);
            $osLt       = (int)($r->os_lt ?? 0);
            $osDpk      = (int)($r->os_dpk ?? 0);
            $osPotensi  = (int)($r->os_potensi ?? 0);
            $osKl       = (int)($r->os_kl ?? 0);

            $rr          = ($osTotal > 0) ? round(($osL0 / $osTotal) * 100, 2) : null;
            $pctLt       = ($osTotal > 0) ? round(($osLt / $osTotal) * 100, 2) : null;
            $pctDpk      = ($osTotal > 0) ? round(($osDpk / $osTotal) * 100, 2) : null;
            $pctPotensi  = ($osTotal > 0) ? round(($osPotensi / $osTotal) * 100, 2) : null;
            $pctKl       = ($osTotal > 0) ? round(($osKl / $osTotal) * 100, 2) : null;

            $pack = [
                'os_total'      => $osTotal,
                'os_l0'         => $osL0,
                'os_lt'         => $osLt,
                'os_dpk'        => $osDpk,
                'os_potensi'    => $osPotensi,
                'os_kl'         => $osKl,
                'rr'            => $rr,
                'pct_lt'        => $pctLt,
                'pct_dpk'       => $pctDpk,
                'pct_potensi'   => $pctPotensi,
                'pct_kl'        => $pctKl,
            ];

            $staffByDate[$ao][(string)$r->d] = $pack;

            if (!isset($scopeByDate[(string)$r->d])) {
                $scopeByDate[(string)$r->d] = [
                    'os_total'      => 0,
                    'os_l0'         => 0,
                    'os_lt'         => 0,
                    'os_dpk'        => 0,
                    'os_potensi'    => 0,
                    'os_kl'         => 0,
                    'rr'            => null,
                    'pct_lt'        => null,
                    'pct_dpk'       => null,
                    'pct_potensi'   => null,
                    'pct_kl'        => null,
                ];
            }

            $scopeByDate[(string)$r->d]['os_total']   += $osTotal;
            $scopeByDate[(string)$r->d]['os_l0']      += $osL0;
            $scopeByDate[(string)$r->d]['os_lt']      += $osLt;
            $scopeByDate[(string)$r->d]['os_dpk']     += $osDpk;
            $scopeByDate[(string)$r->d]['os_potensi'] += $osPotensi;
            $scopeByDate[(string)$r->d]['os_kl']      += $osKl;
        }

        \Log::info('TLFE scopeByDate total', $scopeByDate);

        foreach ($scopeByDate as $d => $agg) {
            $osTotal = (int)($agg['os_total'] ?? 0);
            $osL0    = (int)($agg['os_l0'] ?? 0);
            $osLt    = (int)($agg['os_lt'] ?? 0);
            $osDpk   = (int)($agg['os_dpk'] ?? 0);
            $osPot   = (int)($agg['os_potensi'] ?? 0);
            $osKl    = (int)($agg['os_kl'] ?? 0);

            $scopeByDate[$d]['rr']          = $osTotal > 0 ? round(($osL0 / $osTotal) * 100, 2) : null;
            $scopeByDate[$d]['pct_lt']      = $osTotal > 0 ? round(($osLt / $osTotal) * 100, 2) : null;
            $scopeByDate[$d]['pct_dpk']     = $osTotal > 0 ? round(($osDpk / $osTotal) * 100, 2) : null;
            $scopeByDate[$d]['pct_potensi'] = $osTotal > 0 ? round(($osPot / $osTotal) * 100, 2) : null;
            $scopeByDate[$d]['pct_kl']      = $osTotal > 0 ? round(($osKl / $osTotal) * 100, 2) : null;
        }

        /*
         * ============================================================
         * 5) ACTIVE DATASET: ALL SCOPE atau PER FE
         * ============================================================
         */
        $activeByDate = $scopeMode === 'single'
            ? ($staffByDate[$selectedAoCode] ?? [])
            : $scopeByDate;

        $metrics = [
            'os_total', 'os_l0', 'os_lt', 'os_dpk', 'os_potensi', 'os_kl',
            'rr', 'pct_lt', 'pct_dpk', 'pct_potensi', 'pct_kl',
        ];

        $series = [];
        foreach ($metrics as $metric) {
            $series[$metric] = [];
            foreach ($labels as $d) {
                $series[$metric][] = $activeByDate[$d][$metric] ?? null;
            }
        }

        /*
         * ============================================================
         * 6) DATASET BY METRIC UNTUK CHART
         * - all scope: total scope + line masing-masing FE
         * - single   : FE terpilih saja
         * ============================================================
         */
        $memberMapByAo = $scopeMembers
            ->keyBy(fn ($m) => $this->normalizeAoCode($m['ao_code'] ?? null));

        $metricLabels = [
            'os_total'    => 'OS Total',
            'os_l0'       => 'OS L0',
            'os_lt'       => 'OS LT',
            'os_dpk'      => 'OS DPK',
            'os_potensi'  => 'OS Potensi',
            'os_kl'       => 'OS KL',
            'rr'          => 'RR (% L0)',
            'pct_lt'      => '% LT',
            'pct_dpk'     => '% DPK',
            'pct_potensi' => '% Potensi',
            'pct_kl'      => '% KL',
        ];

        $datasetsByMetric = [];

        foreach ($metricLabels as $metric => $label) {
            $sets = [];

            if ($scopeMode === 'all') {
                $scopeData = [];
                foreach ($labels as $d) {
                    $scopeData[] = $scopeByDate[$d][$metric] ?? null;
                }

                $sets[] = [
                    'label'   => $scopeLabel . ' - ' . $label,
                    'data'    => $scopeData,
                    'scope'   => 'all',
                    'ao_code' => null,
                    'user_id' => null,
                ];

                foreach ($scopeAoCodes as $ao) {
                    $staffData = [];
                    foreach ($labels as $d) {
                        $staffData[] = $staffByDate[$ao][$d][$metric] ?? null;
                    }

                    $m = $memberMapByAo->get($ao);
                    $sets[] = [
                        'label'   => (string)($m['name'] ?? $ao),
                        'data'    => $staffData,
                        'scope'   => 'staff',
                        'ao_code' => $ao,
                        'user_id' => (int)($m['user_id'] ?? 0),
                    ];
                }
            } else {
                $staffData = [];
                foreach ($labels as $d) {
                    $staffData[] = $staffByDate[$selectedAoCode][$d][$metric] ?? null;
                }

                $sets[] = [
                    'label'   => $scopeLabel . ' - ' . $label,
                    'data'    => $staffData,
                    'scope'   => 'staff',
                    'ao_code' => $selectedAoCode,
                    'user_id' => $selectedFeUserId,
                ];
            }

            $datasetsByMetric[$metric] = $sets;
        }

        /*
         * ============================================================
         * 7) LATEST + PREV
         * ============================================================
         */
        $latestDate = null;
        if (!empty($activeByDate)) {
            $datesWithData = array_keys($activeByDate);
            sort($datesWithData);
            $latestDate = end($datesWithData) ?: null;
        }

        $prevAvailDate = null;
        if ($latestDate) {
            for ($i = count($labels) - 1; $i >= 0; $i--) {
                $d = $labels[$i] ?? null;
                if (!$d) {
                    continue;
                }
                if ($d === $latestDate) {
                    continue;
                }
                if (isset($activeByDate[$d])) {
                    $prevAvailDate = $d;
                    break;
                }
            }
        }

        $latestPack = $latestDate ? ($activeByDate[$latestDate] ?? null) : null;
        $prevPack   = $prevAvailDate ? ($activeByDate[$prevAvailDate] ?? null) : null;
        $prevDate   = $prevAvailDate;

        $latestPosDate = $latestDate
            ? Carbon::parse($latestDate)->toDateString()
            : $latestInKpi->toDateString();

        $prevSnapMonth = Carbon::parse($latestPosDate)
            ->subMonthNoOverflow()
            ->startOfMonth()
            ->toDateString();

        /*
         * ============================================================
         * 8) CARDS VALUE & DELTA (H vs H-1)
         * ============================================================
         */
        $latestOs         = (int)($latestPack['os_total'] ?? 0);
        $latestL0         = (int)($latestPack['os_l0'] ?? 0);
        $latestLT         = (int)($latestPack['os_lt'] ?? 0);
        $latestDPK        = (int)($latestPack['os_dpk'] ?? 0);
        $latestPotensi    = (int)($latestPack['os_potensi'] ?? 0);
        $latestKl         = (int)($latestPack['os_kl'] ?? 0);

        $latestRR         = $latestPack['rr'] ?? null;
        $latestPctLt      = $latestPack['pct_lt'] ?? null;
        $latestPctDpk     = $latestPack['pct_dpk'] ?? null;
        $latestPctPotensi = $latestPack['pct_potensi'] ?? null;
        $latestPctKl      = $latestPack['pct_kl'] ?? null;

        $prevOs           = $prevPack ? (int)($prevPack['os_total'] ?? 0) : null;
        $prevL0           = $prevPack ? (int)($prevPack['os_l0'] ?? 0) : null;
        $prevLT           = $prevPack ? (int)($prevPack['os_lt'] ?? 0) : null;
        $prevDPK          = $prevPack ? (int)($prevPack['os_dpk'] ?? 0) : null;
        $prevPotensi      = $prevPack ? (int)($prevPack['os_potensi'] ?? 0) : null;
        $prevKl           = $prevPack ? (int)($prevPack['os_kl'] ?? 0) : null;

        $prevRR           = $prevPack ? ($prevPack['rr'] ?? null) : null;
        $prevPctLt        = $prevPack ? ($prevPack['pct_lt'] ?? null) : null;
        $prevPctDpk       = $prevPack ? ($prevPack['pct_dpk'] ?? null) : null;
        $prevPctPotensi   = $prevPack ? ($prevPack['pct_potensi'] ?? null) : null;
        $prevPctKl        = $prevPack ? ($prevPack['pct_kl'] ?? null) : null;

        $deltaOs          = is_null($prevOs) ? null : ($latestOs - $prevOs);
        $deltaL0          = is_null($prevL0) ? null : ($latestL0 - $prevL0);
        $deltaLT          = is_null($prevLT) ? null : ($latestLT - $prevLT);
        $deltaDPK         = is_null($prevDPK) ? null : ($latestDPK - $prevDPK);
        $deltaPotensi     = is_null($prevPotensi) ? null : ($latestPotensi - $prevPotensi);
        $deltaKl          = is_null($prevKl) ? null : ($latestKl - $prevKl);

        $deltaRR          = (is_null($prevRR) || is_null($latestRR)) ? null : round(((float)$latestRR - (float)$prevRR), 2);
        $deltaPctLt       = (is_null($prevPctLt) || is_null($latestPctLt)) ? null : round(((float)$latestPctLt - (float)$prevPctLt), 2);
        $deltaPctDpk      = (is_null($prevPctDpk) || is_null($latestPctDpk)) ? null : round(((float)$latestPctDpk - (float)$prevPctDpk), 2);
        $deltaPctPotensi  = (is_null($prevPctPotensi) || is_null($latestPctPotensi)) ? null : round(((float)$latestPctPotensi - (float)$prevPctPotensi), 2);
        $deltaPctKl       = (is_null($prevPctKl) || is_null($latestPctKl)) ? null : round(((float)$latestPctKl - (float)$prevPctKl), 2);

        $cards = [
            'os' => [
                'label' => 'OS',
                'value' => $latestOs,
                'base'  => $prevOs,
                'delta' => $deltaOs,
            ],
            'l0' => [
                'label' => 'L0',
                'value' => $latestL0,
                'base'  => $prevL0,
                'delta' => $deltaL0,
                'extra' => [
                    'rr' => [
                        'label' => 'RR',
                        'value' => $latestRR,
                        'base'  => $prevRR,
                        'delta' => $deltaRR,
                    ],
                ],
            ],
            'lt' => [
                'label' => 'LT',
                'value' => $latestLT,
                'base'  => $prevLT,
                'delta' => $deltaLT,
                'extra' => [
                    'pct_lt' => [
                        'label' => '%LT',
                        'value' => $latestPctLt,
                        'base'  => $prevPctLt,
                        'delta' => $deltaPctLt,
                    ],
                ],
            ],
            'dpk' => [
                'label' => 'DPK',
                'value' => $latestDPK,
                'base'  => $prevDPK,
                'delta' => $deltaDPK,
                'extra' => [
                    'pct_dpk' => [
                        'label' => '%DPK',
                        'value' => $latestPctDpk,
                        'base'  => $prevPctDpk,
                        'delta' => $deltaPctDpk,
                    ],
                ],
            ],
            'potensi' => [
                'label' => 'Potensi',
                'value' => $latestPotensi,
                'base'  => $prevPotensi,
                'delta' => $deltaPotensi,
                'extra' => [
                    'pct_potensi' => [
                        'label' => '%Potensi',
                        'value' => $latestPctPotensi,
                        'base'  => $prevPctPotensi,
                        'delta' => $deltaPctPotensi,
                    ],
                ],
            ],
            'kl' => [
                'label' => 'KL',
                'value' => $latestKl,
                'base'  => $prevKl,
                'delta' => $deltaKl,
                'extra' => [
                    'pct_kl' => [
                        'label' => '%KL',
                        'value' => $latestPctKl,
                        'base'  => $prevPctKl,
                        'delta' => $deltaPctKl,
                    ],
                ],
            ],
        ];

        /*
         * ============================================================
         * 9) CARD MONTH TO DATE (EOM vs POSISI TERAKHIR)
         * ============================================================
         */
        $eomMonth = Carbon::parse($latestPosDate)
            ->subMonthNoOverflow()
            ->startOfMonth()
            ->toDateString();

        $eomAgg = DB::table('loan_account_snapshots_monthly as m')
            ->selectRaw("
                ROUND(SUM(COALESCE(m.outstanding,0))) as os,

                ROUND(SUM(CASE
                    WHEN COALESCE(m.kolek,0)=1
                     AND COALESCE(m.ft_pokok,0)=0
                     AND COALESCE(m.ft_bunga,0)=0
                    THEN COALESCE(m.outstanding,0) ELSE 0 END
                )) as l0,

                ROUND(SUM(CASE
                    WHEN COALESCE(m.kolek,0)=1
                     AND (COALESCE(m.ft_pokok,0)=1 OR COALESCE(m.ft_bunga,0)=1)
                    THEN COALESCE(m.outstanding,0) ELSE 0 END
                )) as lt,

                ROUND(SUM(CASE
                    WHEN COALESCE(m.kolek,0)=2
                     AND (COALESCE(m.ft_pokok,0)=2 OR COALESCE(m.ft_bunga,0)=2)
                    THEN COALESCE(m.outstanding,0) ELSE 0 END
                )) as dpk,

                ROUND(SUM(CASE
                    WHEN COALESCE(m.kolek,0)=2
                     AND (COALESCE(m.ft_pokok,0)=3 OR COALESCE(m.ft_bunga,0)=3)
                    THEN COALESCE(m.outstanding,0) ELSE 0 END
                )) as potensi,

                ROUND(SUM(CASE
                    WHEN COALESCE(m.kolek,0)=3
                    THEN COALESCE(m.outstanding,0) ELSE 0 END
                )) as kl
            ")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'm.ao_code', $activeAoCodes);
            })
            ->first();

        $eomOs       = (int)($eomAgg->os ?? 0);
        $eomL0       = (int)($eomAgg->l0 ?? 0);
        $eomLt       = (int)($eomAgg->lt ?? 0);
        $eomDpk      = (int)($eomAgg->dpk ?? 0);
        $eomPotensi  = (int)($eomAgg->potensi ?? 0);
        $eomKl       = (int)($eomAgg->kl ?? 0);

        $eomRr           = $eomOs > 0 ? round(($eomL0 / $eomOs) * 100, 2) : null;
        $eomPctLt        = $eomOs > 0 ? round(($eomLt / $eomOs) * 100, 2) : null;
        $eomPctDpk       = $eomOs > 0 ? round(($eomDpk / $eomOs) * 100, 2) : null;
        $eomPctPotensi   = $eomOs > 0 ? round(($eomPotensi / $eomOs) * 100, 2) : null;
        $eomPctKl        = $eomOs > 0 ? round(($eomKl / $eomOs) * 100, 2) : null;

        $lastAgg = DB::table('kpi_os_daily_aos as d')
            ->selectRaw("
                ROUND(SUM(d.os_total))    as os,
                ROUND(SUM(d.os_l0))       as l0,
                ROUND(SUM(d.os_lt))       as lt,
                ROUND(SUM(d.os_dpk))      as dpk,
                ROUND(SUM(d.os_potensi))  as potensi,
                ROUND(SUM(d.os_kl))       as kl
            ")
            ->whereDate('d.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'd.ao_code', $activeAoCodes);
            })
            ->first();

        $lastOs       = (int)($lastAgg->os ?? 0);
        $lastL0       = (int)($lastAgg->l0 ?? 0);
        $lastLt       = (int)($lastAgg->lt ?? 0);
        $lastDpk      = (int)($lastAgg->dpk ?? 0);
        $lastPotensi  = (int)($lastAgg->potensi ?? 0);
        $lastKl       = (int)($lastAgg->kl ?? 0);

        $lastRr           = $lastOs > 0 ? round(($lastL0 / $lastOs) * 100, 2) : null;
        $lastPctLt        = $lastOs > 0 ? round(($lastLt / $lastOs) * 100, 2) : null;
        $lastPctDpk       = $lastOs > 0 ? round(($lastDpk / $lastOs) * 100, 2) : null;
        $lastPctPotensi   = $lastOs > 0 ? round(($lastPotensi / $lastOs) * 100, 2) : null;
        $lastPctKl        = $lastOs > 0 ? round(($lastKl / $lastOs) * 100, 2) : null;

        $cardsMtd = [
            'os' => [
                'label' => 'OS',
                'value' => $lastOs,
                'base'  => $eomOs,
                'delta' => $lastOs - $eomOs,
            ],
            'l0' => [
                'label' => 'L0',
                'value' => $lastL0,
                'base'  => $eomL0,
                'delta' => $lastL0 - $eomL0,
                'extra' => [
                    'rr' => [
                        'label' => 'RR',
                        'value' => $lastRr,
                        'base'  => $eomRr,
                        'delta' => (is_null($lastRr) || is_null($eomRr)) ? null : round($lastRr - $eomRr, 2),
                    ],
                ],
            ],
            'lt' => [
                'label' => 'LT',
                'value' => $lastLt,
                'base'  => $eomLt,
                'delta' => $lastLt - $eomLt,
                'extra' => [
                    'pct_lt' => [
                        'label' => '%LT',
                        'value' => $lastPctLt,
                        'base'  => $eomPctLt,
                        'delta' => (is_null($lastPctLt) || is_null($eomPctLt)) ? null : round($lastPctLt - $eomPctLt, 2),
                    ],
                ],
            ],
            'dpk' => [
                'label' => 'DPK',
                'value' => $lastDpk,
                'base'  => $eomDpk,
                'delta' => $lastDpk - $eomDpk,
                'extra' => [
                    'pct_dpk' => [
                        'label' => '%DPK',
                        'value' => $lastPctDpk,
                        'base'  => $eomPctDpk,
                        'delta' => (is_null($lastPctDpk) || is_null($eomPctDpk)) ? null : round($lastPctDpk - $eomPctDpk, 2),
                    ],
                ],
            ],
            'potensi' => [
                'label' => 'Potensi',
                'value' => $lastPotensi,
                'base'  => $eomPotensi,
                'delta' => $lastPotensi - $eomPotensi,
                'extra' => [
                    'pct_potensi' => [
                        'label' => '%Potensi',
                        'value' => $lastPctPotensi,
                        'base'  => $eomPctPotensi,
                        'delta' => (is_null($lastPctPotensi) || is_null($eomPctPotensi)) ? null : round($lastPctPotensi - $eomPctPotensi, 2),
                    ],
                ],
            ],
            'kl' => [
                'label' => 'KL',
                'value' => $lastKl,
                'base'  => $eomKl,
                'delta' => $lastKl - $eomKl,
                'extra' => [
                    'pct_kl' => [
                        'label' => '%KL',
                        'value' => $lastPctKl,
                        'base'  => $eomPctKl,
                        'delta' => (is_null($lastPctKl) || is_null($eomPctKl)) ? null : round($lastPctKl - $eomPctKl, 2),
                    ],
                ],
            ],
        ];

        $cardsMtdMeta = [
            'eomMonth' => $eomMonth,
            'lastDate' => $latestPosDate,
        ];

        /*
         * ============================================================
         * 10) BOUNCE COMPARE DATE
         * ============================================================
         */
        $prevPosDate = $prevDate ?: Carbon::parse($latestPosDate)->subDay()->toDateString();

        /*
         * ============================================================
         * 11) BUCKET SQL FE
         * ============================================================
         */
        $bucketSql = function (string $alias): string {
            return "(
                CASE
                  WHEN COALESCE({$alias}.kolek,0) = 3 THEN 'KL'
                  WHEN COALESCE({$alias}.kolek,0) = 2
                       AND (COALESCE({$alias}.ft_pokok,0) = 3 OR COALESCE({$alias}.ft_bunga,0) = 3) THEN 'POTENSI'
                  WHEN COALESCE({$alias}.kolek,0) = 2
                       AND (COALESCE({$alias}.ft_pokok,0) = 2 OR COALESCE({$alias}.ft_bunga,0) = 2) THEN 'DPK'
                  WHEN COALESCE({$alias}.kolek,0) = 1
                       AND (COALESCE({$alias}.ft_pokok,0) = 1 OR COALESCE({$alias}.ft_bunga,0) = 1) THEN 'LT'
                  WHEN COALESCE({$alias}.kolek,0) = 1
                       AND COALESCE({$alias}.ft_pokok,0) = 0
                       AND COALESCE({$alias}.ft_bunga,0) = 0 THEN 'L0'
                  ELSE '-'
                END
            )";
        };

        /*
         * ============================================================
         * 12) PLANNED SOURCE: RKH HARI INI (SELURUH SCOPE FE)
         * ============================================================
         */
        $today = now()->toDateString();

        $subPlanToday = DB::table('rkh_headers as h')
            ->join('rkh_details as d', 'd.rkh_id', '=', 'h.id')
            ->whereIn('h.user_id', $scopeUserIds)
            ->whereDate('h.tanggal', $today)
            ->selectRaw("
                TRIM(d.account_no) as account_no,
                1 as planned_today,
                MAX(h.tanggal) as plan_visit_date,
                MAX(h.status) as plan_status
            ")
            ->groupBy(DB::raw("TRIM(d.account_no)"));

        $subLastVisitMeta = $this->subLastVisitMeta($scopeUserIds);

        /*
         * ============================================================
         * 13) INSIGHT PENYEBAB
         * ============================================================
         */
        $l0ToLtAgg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(la.outstanding)) as os
            ")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->where('m.ft_pokok', 0)
            ->where('m.ft_bunga', 0)
            ->where(function ($q) {
                $q->where('la.ft_pokok', '>', 0)
                    ->orWhere('la.ft_bunga', '>', 0);
            })
            ->first();

        $l0ToLtNoa = (int)($l0ToLtAgg->noa ?? 0);
        $l0ToLtOs  = (int)($l0ToLtAgg->os ?? 0);

        /*
         * ============================================================
         * 14) H-1 -> H
         * ============================================================
         */
        $ltToL0Agg = DB::table('loan_accounts as t')
            ->join('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 't.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(t.outstanding)) as os
            ")
            ->whereDate('t.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 't.ao_code', $activeAoCodes);
            })
            ->where(function ($q) {
                $q->where('p.ft_pokok', 1)->orWhere('p.ft_bunga', 1);
            })
            ->where('t.ft_pokok', 0)
            ->where('t.ft_bunga', 0)
            ->first();

        $ltToL0Noa = (int)($ltToL0Agg->noa ?? 0);
        $ltToL0Os  = (int)($ltToL0Agg->os ?? 0);

        $ltToDpkAgg = DB::table('loan_accounts as t')
            ->join('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 't.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(t.outstanding)) as os
            ")
            ->whereDate('t.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 't.ao_code', $activeAoCodes);
            })
            ->where(function ($q) {
                $q->where('p.ft_pokok', 1)->orWhere('p.ft_bunga', 1);
            })
            ->where(function ($q) {
                $q->where('t.ft_pokok', 2)
                    ->orWhere('t.ft_bunga', 2)
                    ->orWhere('t.kolek', 2);
            })
            ->first();

        $ltToDpkNoa = (int)($ltToDpkAgg->noa ?? 0);
        $ltToDpkOs  = (int)($ltToDpkAgg->os ?? 0);

        $pos = Carbon::parse($latestPosDate);
        $d1  = $pos->copy()->addDay()->toDateString();
        $d2  = $pos->copy()->addDays(2)->toDateString();

        $posLiteral = $pos->toDateString();

        $dueBase = "STR_TO_DATE(CONCAT(DATE_FORMAT('$posLiteral','%Y-%m'),'-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')";
        $dueNext = "STR_TO_DATE(CONCAT(DATE_FORMAT(DATE_ADD('$posLiteral', INTERVAL 1 MONTH),'%Y-%m'),'-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')";
        $dueSmart = "(
            CASE
              WHEN $dueBase IS NULL THEN $dueNext
              WHEN $dueBase < '$posLiteral' THEN $dueNext
              ELSE $dueBase
            END
        )";

        $jtNext2Agg = DB::table('loan_accounts as la')
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(la.outstanding)) as os
            ")
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->whereNotNull('la.installment_day')
            ->where('la.installment_day', '>=', 1)
            ->where('la.installment_day', '<=', 31)
            ->whereBetween(DB::raw($dueSmart), [$d1, $d2])
            ->first();

        $jtNext2Noa = (int)($jtNext2Agg->noa ?? 0);
        $jtNext2Os  = (int)($jtNext2Agg->os ?? 0);

        /*
         * ============================================================
         * 15) COHORT LT EOM -> HARI INI
         * ============================================================
         */
        $ltEomToDpkAgg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("COUNT(*) as noa, ROUND(COALESCE(SUM(la.outstanding),0)) as os")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->where(function ($q) {
                $q->where('m.ft_pokok', 1)->orWhere('m.ft_bunga', 1);
            })
            ->where(function ($q) {
                $q->where('la.ft_pokok', 2)->orWhere('la.ft_bunga', 2)->orWhere('la.kolek', 2);
            })
            ->first();

        $ltEomToDpkNoa = (int)($ltEomToDpkAgg->noa ?? 0);
        $ltEomToDpkOs  = (int)($ltEomToDpkAgg->os ?? 0);

        $ltEomToL0Agg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("COUNT(*) as noa, ROUND(SUM(la.outstanding)) as os")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->where(function ($q) {
                $q->where('m.ft_pokok', 1)->orWhere('m.ft_bunga', 1);
            })
            ->where('la.ft_pokok', 0)
            ->where('la.ft_bunga', 0)
            ->first();

        $ltEomToL0Noa = (int)($ltEomToL0Agg->noa ?? 0);
        $ltEomToL0Os  = (int)($ltEomToL0Agg->os ?? 0);

        /*
         * ============================================================
         * 16) FE TAMBAHAN: DPK EOM -> POTENSI / POTENSI EOM -> KL
         * ============================================================
         */
        $dpkToPotensiAgg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(COALESCE(la.outstanding,0))) as os
            ")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->where(function ($q) {
                $q->where('m.kolek', 2)
                    ->where(function ($qq) {
                        $qq->where('m.ft_pokok', 2)
                            ->orWhere('m.ft_bunga', 2);
                    });
            })
            ->where(function ($q) {
                $q->where('la.kolek', 2)
                    ->where(function ($qq) {
                        $qq->where('la.ft_pokok', 3)
                            ->orWhere('la.ft_bunga', 3);
                    });
            })
            ->first();

        $dpkToPotensiNoa = (int)($dpkToPotensiAgg->noa ?? 0);
        $dpkToPotensiOs  = (int)($dpkToPotensiAgg->os ?? 0);

        $potensiToKlAgg = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->selectRaw("
                COUNT(*) as noa,
                ROUND(SUM(COALESCE(la.outstanding,0))) as os
            ")
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->where(function ($q) {
                $q->where('m.kolek', 2)
                    ->where(function ($qq) {
                        $qq->where('m.ft_pokok', 3)
                            ->orWhere('m.ft_bunga', 3);
                    });
            })
            ->where('la.kolek', 3)
            ->first();

        $potensiToKlNoa = (int)($potensiToKlAgg->noa ?? 0);
        $potensiToKlOs  = (int)($potensiToKlAgg->os ?? 0);

        /*
         * ============================================================
         * 17) LIST: LT EOM -> DPK
         * ============================================================
         */
        $ltEomToDpk = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as os"),
                'la.dpd',
                'la.kolek',
                'la.ft_pokok',
                'la.ft_bunga',
                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->where(function ($q) {
                $q->where('m.ft_pokok', 1)->orWhere('m.ft_bunga', 1);
            })
            ->where(function ($q) {
                $q->where('la.ft_pokok', 2)->orWhere('la.ft_bunga', 2)->orWhere('la.kolek', 2);
            })
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        /*
         * ============================================================
         * 18) LIST FE: DPK EOM -> POTENSI
         * ============================================================
         */
        $dpkToPotensi = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->leftJoinSub($subLastVisitMeta, 'lv', function ($j) {
                $j->on(DB::raw("TRIM(lv.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(COALESCE(la.outstanding,0)) as os"),
                'la.dpd',
                'la.kolek',
                'la.ft_pokok',
                'la.ft_bunga',
                DB::raw("COALESCE(m.kolek,0) as eom_kolek"),
                DB::raw("COALESCE(m.ft_pokok,0) as eom_ft_pokok"),
                DB::raw("COALESCE(m.ft_bunga,0) as eom_ft_bunga"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->where(function ($q) {
                $q->where('m.kolek', 2)
                    ->where(function ($qq) {
                        $qq->where('m.ft_pokok', 2)
                            ->orWhere('m.ft_bunga', 2);
                    });
            })
            ->where(function ($q) {
                $q->where('la.kolek', 2)
                    ->where(function ($qq) {
                        $qq->where('la.ft_pokok', 3)
                            ->orWhere('la.ft_bunga', 3);
                    });
            })
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        /*
         * ============================================================
         * 19) LIST FE: POTENSI EOM -> KL
         * ============================================================
         */
        $potensiToKl = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->leftJoinSub($subLastVisitMeta, 'lv', function ($j) {
                $j->on(DB::raw("TRIM(lv.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(COALESCE(la.outstanding,0)) as os"),
                'la.dpd',
                'la.kolek',
                'la.ft_pokok',
                'la.ft_bunga',
                DB::raw("COALESCE(m.kolek,0) as eom_kolek"),
                DB::raw("COALESCE(m.ft_pokok,0) as eom_ft_pokok"),
                DB::raw("COALESCE(m.ft_bunga,0) as eom_ft_bunga"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->where(function ($q) {
                $q->where('m.kolek', 2)
                    ->where(function ($qq) {
                        $qq->where('m.ft_pokok', 3)
                            ->orWhere('m.ft_bunga', 3);
                    });
            })
            ->where('la.kolek', 3)
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();
                    /*
         * ============================================================
         * 20) BOUNCE RISK
         * ============================================================
         */
        $bounce = [
            'prevPosDate'            => $prevPosDate,
            'd1'                     => $d1,
            'd2'                     => $d2,

            'lt_to_l0_noa'           => $ltToL0Noa,
            'lt_to_l0_os'            => $ltToL0Os,
            'lt_to_dpk_noa'          => $ltToDpkNoa,
            'lt_to_dpk_os'           => $ltToDpkOs,

            'jt_next2_noa'           => $jtNext2Noa,
            'jt_next2_os'            => $jtNext2Os,

            'lt_eom_to_dpk_noa'      => $ltEomToDpkNoa,
            'lt_eom_to_dpk_os'       => $ltEomToDpkOs,
            'lt_eom_to_l0_noa'       => $ltEomToL0Noa,
            'lt_eom_to_l0_os'        => $ltEomToL0Os,

            'dpk_eom_to_potensi_noa' => $dpkToPotensiNoa,
            'dpk_eom_to_potensi_os'  => $dpkToPotensiOs,
            'potensi_eom_to_kl_noa'  => $potensiToKlNoa,
            'potensi_eom_to_kl_os'   => $potensiToKlOs,

            'signal_cure'            => (!is_null($deltaL0) && !is_null($deltaLT) && $deltaL0 > 0 && $deltaLT < 0),
            'signal_jtsoon'          => ($jtNext2Noa > 0),
            'signal_bounce_risk'     => (
                (!is_null($deltaL0) && !is_null($deltaLT) && $deltaL0 > 0 && $deltaLT < 0) && ($jtNext2Noa > 0)
            ),
        ];

        /*
         * ============================================================
         * 21) TABEL 1) JT BULAN INI
         * ============================================================
         */
        $now        = Carbon::parse($latestPosDate);
        $monthStart = $now->copy()->startOfMonth()->toDateString();
        $monthEnd   = $now->copy()->endOfMonth()->toDateString();

        $dueThisMonth = DB::table('loan_accounts as la')
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->leftJoinSub($subLastVisitMeta, 'lv', function ($j) {
                $j->on(DB::raw("TRIM(lv.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as outstanding"),
                'la.maturity_date',
                'la.dpd',
                'la.kolek',
                DB::raw("COALESCE(p.ft_pokok, 0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga, 0) as prev_ft_bunga"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),
                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),
                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->whereNotNull('la.maturity_date')
            ->whereBetween('la.maturity_date', [$monthStart, $monthEnd])
            ->orderBy('la.maturity_date')
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        /*
         * ============================================================
         * 22) TABEL 2) COHORT LT EOM -> STATUS HARI INI
         * ============================================================
         */
        $ltEom = DB::table('loan_account_snapshots_monthly as m')
            ->join('loan_accounts as la', 'la.account_no', '=', 'm.account_no')
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->leftJoinSub($subLastVisitMeta, 'lv', function ($j) {
                $j->on(DB::raw("TRIM(lv.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as os"),
                'la.ft_pokok',
                'la.ft_bunga',
                'la.dpd',
                'la.kolek',

                DB::raw("COALESCE(m.ft_pokok,0) as eom_ft_pokok"),
                DB::raw("COALESCE(m.ft_bunga,0) as eom_ft_bunga"),
                DB::raw("COALESCE(m.kolek,0) as eom_kolek"),

                DB::raw("COALESCE(p.ft_pokok,0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga,0) as prev_ft_bunga"),
                DB::raw("COALESCE(p.kolek,0) as prev_kolek"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_status as plan_status"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->whereDate('la.position_date', $latestPosDate)
            ->where('la.outstanding', '>', 0)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->where(function ($q) {
                $q->where('m.kolek', 1)
                    ->where(function ($qq) {
                        $qq->where('m.ft_pokok', 1)
                            ->orWhere('m.ft_bunga', 1);
                    });
            })
            ->orderByDesc('la.dpd')
            ->orderByDesc('la.outstanding')
            ->limit(300)
            ->get();

        /*
         * ============================================================
         * 23) PARTISI LT EOM
         * ============================================================
         */
        $isDpk = function ($r) {
            return ((int)($r->kolek ?? 0) === 2)
                && (
                    ((int)($r->ft_pokok ?? 0) === 2) ||
                    ((int)($r->ft_bunga ?? 0) === 2)
                );
        };

        $isPotensi = function ($r) {
            return ((int)($r->kolek ?? 0) === 2)
                && (
                    ((int)($r->ft_pokok ?? 0) === 3) ||
                    ((int)($r->ft_bunga ?? 0) === 3)
                );
        };

        $isKl = function ($r) {
            return ((int)($r->kolek ?? 0) === 3);
        };

        $isL0 = function ($r) {
            return ((int)($r->kolek ?? 0) === 1)
                && ((int)($r->ft_pokok ?? 0) === 0)
                && ((int)($r->ft_bunga ?? 0) === 0);
        };

        $isLtOnly = function ($r) use ($isDpk, $isPotensi, $isKl, $isL0) {
            if ($isDpk($r)) {
                return false;
            }
            if ($isPotensi($r)) {
                return false;
            }
            if ($isKl($r)) {
                return false;
            }
            if ($isL0($r)) {
                return false;
            }

            return ((int)($r->kolek ?? 0) === 1)
                && (
                    ((int)($r->ft_pokok ?? 0) === 1) ||
                    ((int)($r->ft_bunga ?? 0) === 1)
                );
        };

        $ltToDpk   = collect($ltEom)->filter($isDpk)->values();
        $ltStillLt = collect($ltEom)->filter($isLtOnly)->values();

        $ltToDpkNoa = (int)$ltToDpk->count();
        $ltToDpkOs  = (int)$ltToDpk->sum(fn ($r) => (int)($r->os ?? 0));

        /*
         * ============================================================
         * 24) TABEL 2A) COHORT L0 EOM -> STATUS POSISI TERAKHIR
         * ============================================================
         */
        $subLaLatestAccKey = DB::table('loan_accounts')
            ->whereDate('position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'ao_code', $activeAoCodes);
            })
            ->selectRaw("
                TRIM(LEADING '0' FROM account_no) as acc_key,
                account_no,
                customer_name,
                LPAD(TRIM(ao_code),6,'0') as ao_code,
                ROUND(outstanding) as os,
                ft_pokok,
                ft_bunga,
                dpd,
                kolek,
                position_date
            ");

        $subLaPrevAccKey = DB::table('loan_accounts')
            ->whereDate('position_date', $prevPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'ao_code', $activeAoCodes);
            })
            ->selectRaw("
                TRIM(LEADING '0' FROM account_no) as acc_key,
                COALESCE(ft_pokok,0) as prev_ft_pokok,
                COALESCE(ft_bunga,0) as prev_ft_bunga,
                COALESCE(kolek,0) as prev_kolek
            ");

        $subPlanTodayAccKey = DB::table('rkh_headers as h')
            ->join('rkh_details as d', 'd.rkh_id', '=', 'h.id')
            ->whereIn('h.user_id', $scopeUserIds)
            ->whereDate('h.tanggal', $today)
            ->selectRaw("
                TRIM(LEADING '0' FROM d.account_no) as acc_key,
                1 as planned_today,
                MAX(h.tanggal) as plan_visit_date,
                MAX(h.status) as plan_status
            ")
            ->groupBy('acc_key');

        $subLastVisitAccKey = DB::query()
            ->fromSub($subLastVisitMeta, 'lv0')
            ->selectRaw("
                TRIM(LEADING '0' FROM lv0.account_no) as acc_key,
                lv0.last_visit_at,
                lv0.hasil_kunjungan
            ");

        $l0Eom = DB::table('loan_account_snapshots_monthly as m')
            ->joinSub($subLaLatestAccKey, 'la', function ($j) {
                $j->on(
                    DB::raw("TRIM(LEADING '0' FROM m.account_no)"),
                    '=',
                    DB::raw("la.acc_key")
                );
            })
            ->leftJoinSub($subPlanTodayAccKey, 'pl', function ($j) {
                $j->on(DB::raw("la.acc_key"), '=', DB::raw("pl.acc_key"));
            })
            ->leftJoinSub($subLastVisitAccKey, 'lv', function ($j) {
                $j->on(DB::raw("la.acc_key"), '=', DB::raw("lv.acc_key"));
            })
            ->leftJoinSub($subLaPrevAccKey, 'p', function ($j) {
                $j->on(DB::raw("la.acc_key"), '=', DB::raw("p.acc_key"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                'la.ao_code',
                'la.os',
                'la.ft_pokok',
                'la.ft_bunga',
                'la.dpd',
                'la.kolek',

                DB::raw("COALESCE(m.ft_pokok,0) as eom_ft_pokok"),
                DB::raw("COALESCE(m.ft_bunga,0) as eom_ft_bunga"),
                DB::raw("COALESCE(m.kolek,0) as eom_kolek"),

                DB::raw("COALESCE(p.prev_ft_pokok,0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.prev_ft_bunga,0) as prev_ft_bunga"),
                DB::raw("COALESCE(p.prev_kolek,0) as prev_kolek"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_status as plan_status"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
            ])
            ->whereDate('m.snapshot_month', $prevSnapMonth)
            ->where(function ($q) use ($activeAoCodes) {
                $q->where(function ($qq) use ($activeAoCodes) {
                    $this->applyAoScope($qq, 'm.ao_code', $activeAoCodes);
                })->orWhere(function ($qq) use ($activeAoCodes) {
                    $this->applyAoScope($qq, 'la.ao_code', $activeAoCodes);
                });
            })
            ->whereRaw("
                COALESCE(m.kolek,0)=1
                AND COALESCE(m.ft_pokok,0)=0
                AND COALESCE(m.ft_bunga,0)=0
            ")
            ->orderByDesc('la.dpd')
            ->orderByDesc('la.os')
            ->limit(300)
            ->get();

        /*
         * ============================================================
         * 25) TABEL 3) JT ANGSURAN MINGGU INI
         * ============================================================
         */
        $weekStart = Carbon::parse($latestPosDate)->startOfWeek(Carbon::MONDAY)->toDateString();
        $weekEnd   = Carbon::parse($latestPosDate)->endOfWeek(Carbon::SUNDAY)->toDateString();

        $ym = Carbon::parse($latestPosDate)->format('Y-m');
        $dueDateExpr = "STR_TO_DATE(CONCAT('$ym','-',LPAD(la.installment_day,2,'0')),'%Y-%m-%d')";

        $jtAngsuran = DB::table('loan_accounts as la')
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->leftJoinSub($subLastVisitMeta, 'lv', function ($j) {
                $j->on(DB::raw("TRIM(lv.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as os"),
                'la.installment_day',
                'la.ft_pokok',
                'la.ft_bunga',
                'la.dpd',
                'la.kolek',
                DB::raw("$dueDateExpr as due_date"),

                DB::raw("COALESCE(p.ft_pokok, 0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga, 0) as prev_ft_bunga"),
                DB::raw("COALESCE(p.kolek, 0) as prev_kolek"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->where('la.outstanding', '>', 0)
            ->whereNotNull('la.installment_day')
            ->where('la.installment_day', '>=', 1)
            ->where('la.installment_day', '<=', 31)
            ->whereBetween(DB::raw($dueDateExpr), [$weekStart, $weekEnd])
            ->orderBy(DB::raw($dueDateExpr))
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        /*
         * ============================================================
         * 26) TABEL 4) OS >= 500JT
         * ============================================================
         */
        $osBig = DB::table('loan_accounts as la')
            ->leftJoin('loan_accounts as p', function ($j) use ($prevPosDate) {
                $j->on('p.account_no', '=', 'la.account_no')
                    ->whereDate('p.position_date', $prevPosDate);
            })
            ->leftJoinSub($subPlanToday, 'pl', function ($j) {
                $j->on(DB::raw("TRIM(pl.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->leftJoinSub($subLastVisitMeta, 'lv', function ($j) {
                $j->on(DB::raw("TRIM(lv.account_no)"), '=', DB::raw("TRIM(la.account_no)"));
            })
            ->select([
                'la.account_no',
                'la.customer_name',
                DB::raw("LPAD(TRIM(la.ao_code),6,'0') as ao_code"),
                DB::raw("ROUND(la.outstanding) as os"),
                'la.ft_pokok',
                'la.ft_bunga',
                'la.dpd',
                'la.kolek',

                DB::raw("COALESCE(p.ft_pokok, 0) as prev_ft_pokok"),
                DB::raw("COALESCE(p.ft_bunga, 0) as prev_ft_bunga"),
                DB::raw("COALESCE(p.kolek, 0) as prev_kolek"),
                DB::raw($bucketSql('p') . " as prev_bucket"),
                DB::raw($bucketSql('la') . " as cur_bucket"),

                DB::raw("lv.last_visit_at as last_visit_at"),
                DB::raw("lv.hasil_kunjungan as hasil_kunjungan"),

                DB::raw("COALESCE(pl.planned_today,0) as planned_today"),
                DB::raw("pl.plan_visit_date as plan_visit_date"),
                DB::raw("pl.plan_status as plan_status"),
            ])
            ->whereDate('la.position_date', $latestPosDate)
            ->where(function ($q) use ($activeAoCodes) {
                $this->applyAoScope($q, 'la.ao_code', $activeAoCodes);
            })
            ->where('la.outstanding', '>', 0)
            ->where('la.outstanding', '>=', 500000000)
            ->orderByDesc('la.outstanding')
            ->limit(200)
            ->get();

        /*
         * ============================================================
         * 27) INSIGHT TEXT
         * ============================================================
         */
        $insight = $this->buildInsight([
            'deltaOs'      => $deltaOs,
            'latestRR'     => $latestRR,
            'deltaRR'      => $deltaRR,
            'latestPctLt'  => $latestPctLt,
            'deltaPctLt'   => $deltaPctLt,
            'l0ToLtNoa'    => $l0ToLtNoa,
            'l0ToLtOs'     => $l0ToLtOs,
            'bounce'       => $bounce,
        ]);

        /*
         * ============================================================
         * 28) RETURN VIEW
         * - sementara pakai blade FE agar cepat hidup
         * - nanti bisa dipisah ke kpi.tlfe.os_daily
         * ============================================================
         */
        return view('kpi.tlfe.os_daily', [
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),

            'labels'            => $labels,
            'datasetsByMetric'  => $datasetsByMetric,

            'latestDate' => $latestDate,
            'prevDate'   => $prevDate,

            'latestOs' => $latestOs,
            'prevOs'   => $prevOs ?? 0,
            'deltaOs'  => $deltaOs ?? 0,

            'latestL0'    => $latestL0,
            'latestLT'    => $latestLT,
            'latestRR'    => $latestRR,
            'latestPctLt' => $latestPctLt,

            'latestPosDate' => $latestPosDate,
            'prevSnapMonth' => $prevSnapMonth,

            'l0ToLtNoa' => $l0ToLtNoa,
            'l0ToLtOs'  => $l0ToLtOs,

            'dueThisMonth'  => $dueThisMonth,
            'dueMonthLabel' => $now->translatedFormat('F Y'),

            'ltLatest'   => $ltEom,
            'jtAngsuran' => $jtAngsuran,
            'weekStart'  => $weekStart,
            'weekEnd'    => $weekEnd,

            'osBig'   => $osBig,

            'cards'   => $cards,
            'bounce'  => $bounce,
            'insight' => $insight,

            'cardsMtd'     => $cardsMtd,
            'cardsMtdMeta' => $cardsMtdMeta,
            'mode'         => $mode,

            'ltToDpk'      => $ltToDpk,
            'ltStillLt'    => $ltStillLt,
            'ltToDpkNoa'   => $ltToDpkNoa,
            'ltToDpkOs'    => $ltToDpkOs,
            'ltEomToDpk'   => $ltEomToDpk,
            'l0Eom'        => $l0Eom,

            'dpkToPotensi'    => $dpkToPotensi,
            'dpkToPotensiNoa' => $dpkToPotensiNoa,
            'dpkToPotensiOs'  => $dpkToPotensiOs,

            'potensiToKl'     => $potensiToKl,
            'potensiToKlNoa'  => $potensiToKlNoa,
            'potensiToKlOs'   => $potensiToKlOs,

            // metadata tambahan utk TLFE blade / filter
            'isTlfeDashboard' => true,
            'scopeMode'       => $scopeMode,          // all | single
            'scopeLabel'      => $scopeLabel,
            'scopeMembers'    => $scopeMembers->values(),
            'scopeAoCodes'    => $scopeAoCodes,
            'activeAoCodes'   => $activeAoCodes,
            'selectedAoCode'  => $selectedAoCode,
            'selectedFeUserId'=> $selectedFeUserId,
            'selectedFeName'  => $selectedFeName,
        ]);
    }

    private function subLastVisitMeta(array $scopeUserIds)
    {
        $scopeUserIds = collect($scopeUserIds)
            ->map(fn ($x) => (int) $x)
            ->filter(fn ($x) => $x > 0)
            ->unique()
            ->values()
            ->all();

        $latestKey = DB::table('ro_visits as rv')
            ->when(!empty($scopeUserIds), fn ($q) => $q->whereIn('rv.user_id', $scopeUserIds))
            ->whereNotNull('rv.account_no')
            ->selectRaw("
                TRIM(rv.account_no) as account_no,
                MAX(
                    CONCAT(
                        DATE_FORMAT(
                            COALESCE(rv.visited_at, rv.visit_date, rv.updated_at, rv.created_at),
                            '%Y-%m-%d %H:%i:%s'
                        ),
                        '#',
                        LPAD(rv.id, 12, '0')
                    )
                ) as max_key
            ")
            ->groupBy(DB::raw("TRIM(rv.account_no)"));

        return DB::table('ro_visits as rv')
            ->when(!empty($scopeUserIds), fn ($q) => $q->whereIn('rv.user_id', $scopeUserIds))
            ->joinSub($latestKey, 'x', function ($join) {
                $join->on(DB::raw("TRIM(rv.account_no)"), '=', 'x.account_no')
                    ->whereRaw("
                        CONCAT(
                            DATE_FORMAT(
                                COALESCE(rv.visited_at, rv.visit_date, rv.updated_at, rv.created_at),
                                '%Y-%m-%d %H:%i:%s'
                            ),
                            '#',
                            LPAD(rv.id, 12, '0')
                        ) = x.max_key
                    ");
            })
            ->selectRaw("
                TRIM(rv.account_no) as account_no,
                COALESCE(rv.visited_at, rv.visit_date, rv.updated_at, rv.created_at) as last_visit_at,
                rv.lkh_note as hasil_kunjungan
            ");
    }

    private function buildInsight(array $x): array
    {
        $good = [];
        $bad  = [];
        $why  = [];
        $risk = [];

        $deltaOs = (int)($x['deltaOs'] ?? 0);
        if ($deltaOs > 0) {
            $good[] = "OS naik vs snapshot sebelumnya sebesar Rp " . number_format($deltaOs, 0, ',', '.');
        }
        if ($deltaOs < 0) {
            $bad[] = "OS turun vs snapshot sebelumnya sebesar Rp " . number_format(abs($deltaOs), 0, ',', '.');
        }

        $rr = $x['latestRR'] ?? null;
        if (!is_null($rr)) {
            if ($rr >= 95) {
                $good[] = "RR sangat baik (≥95%).";
            } elseif ($rr >= 90) {
                $good[] = "RR cukup baik (90–95%).";
            } else {
                $bad[] = "RR menurun/perlu perhatian (<90%).";
            }
        }

        $dRR = $x['deltaRR'] ?? null;
        if (!is_null($dRR)) {
            if ($dRR > 0) {
                $good[] = "RR membaik vs snapshot sebelumnya (+" . number_format((float)$dRR, 2, ',', '.') . " pts).";
            }
            if ($dRR < 0) {
                $bad[] = "RR memburuk vs snapshot sebelumnya (" . number_format((float)$dRR, 2, ',', '.') . " pts).";
            }
        }

        $pctLt = $x['latestPctLt'] ?? null;
        if (!is_null($pctLt)) {
            if ($pctLt <= 5) {
                $good[] = "%LT rendah (≤5%) – kualitas bagus.";
            } elseif ($pctLt <= 10) {
                $good[] = "%LT masih terkendali (5–10%).";
            } else {
                $bad[] = "%LT tinggi (>10%) – ada risiko kualitas portofolio.";
            }
        }

        $dPctLt = $x['deltaPctLt'] ?? null;
        if (!is_null($dPctLt)) {
            if ($dPctLt < 0) {
                $good[] = "%LT turun (membaik) vs snapshot sebelumnya (" . number_format((float)$dPctLt, 2, ',', '.') . " pts).";
            }
            if ($dPctLt > 0) {
                $bad[] = "%LT naik (memburuk) vs snapshot sebelumnya (+" . number_format((float)$dPctLt, 2, ',', '.') . " pts).";
            }
        }

        $noa = (int)($x['l0ToLtNoa'] ?? 0);
        $os  = (int)($x['l0ToLtOs'] ?? 0);
        if ($noa > 0) {
            $why[] = "Indikasi L0 → LT bulan ini: {$noa} NOA, OS ± Rp " . number_format($os, 0, ',', '.') . " (menekan RR & menaikkan %LT).";
        } else {
            $why[] = "Tidak ada indikasi L0 → LT (bulan ini) berdasarkan snapshot bulan lalu vs posisi terakhir (baik untuk stabilitas RR).";
        }

        $bounce = (array)($x['bounce'] ?? []);
        if (!empty($bounce)) {
            $eomToDpkNoa = (int)($bounce['lt_eom_to_dpk_noa'] ?? 0);
            $eomToDpkOs  = (int)($bounce['lt_eom_to_dpk_os'] ?? 0);
            if ($eomToDpkNoa > 0) {
                $risk[] = "Kritis: LT EOM → DPK (FT=2): {$eomToDpkNoa} NOA, OS ± Rp " . number_format($eomToDpkOs, 0, ',', '.') . ".";
            }

            $eomToL0Noa = (int)($bounce['lt_eom_to_l0_noa'] ?? 0);
            $eomToL0Os  = (int)($bounce['lt_eom_to_l0_os'] ?? 0);
            if ($eomToL0Noa > 0) {
                $why[] = "Cure sementara: LT EOM → L0 hari ini: {$eomToL0Noa} NOA, OS ± Rp " . number_format($eomToL0Os, 0, ',', '.') . ".";
            }

            $ltToDpkNoa = (int)($bounce['lt_to_dpk_noa'] ?? 0);
            $ltToDpkOs  = (int)($bounce['lt_to_dpk_os'] ?? 0);
            if ($ltToDpkNoa > 0) {
                $risk[] = "Eskalasi harian: LT → DPK (FT=2): {$ltToDpkNoa} NOA, OS ± Rp " . number_format($ltToDpkOs, 0, ',', '.') . ".";
            }

            $dpkToPotensiNoa = (int)($bounce['dpk_eom_to_potensi_noa'] ?? 0);
            $dpkToPotensiOs  = (int)($bounce['dpk_eom_to_potensi_os'] ?? 0);
            if ($dpkToPotensiNoa > 0) {
                $risk[] = "Perburukan FE: DPK EOM → Potensi hari ini: {$dpkToPotensiNoa} NOA, OS ± Rp " . number_format($dpkToPotensiOs, 0, ',', '.') . ".";
            }

            $potensiToKlNoa = (int)($bounce['potensi_eom_to_kl_noa'] ?? 0);
            $potensiToKlOs  = (int)($bounce['potensi_eom_to_kl_os'] ?? 0);
            if ($potensiToKlNoa > 0) {
                $risk[] = "Perburukan FE: Potensi EOM → KL hari ini: {$potensiToKlNoa} NOA, OS ± Rp " . number_format($potensiToKlOs, 0, ',', '.') . ".";
            }

            $ltToL0Noa = (int)($bounce['lt_to_l0_noa'] ?? 0);
            $ltToL0Os  = (int)($bounce['lt_to_l0_os'] ?? 0);
            if ($ltToL0Noa > 0) {
                $why[] = "Ada perbaikan LT → L0 hari ini (H-1→H): {$ltToL0Noa} NOA, OS ± Rp " . number_format($ltToL0Os, 0, ',', '.') . ".";
            }

            $jtNoa = (int)($bounce['jt_next2_noa'] ?? 0);
            $jtOs  = (int)($bounce['jt_next2_os'] ?? 0);
            if ($jtNoa > 0) {
                $risk[] = "Ada JT angsuran 1–2 hari ke depan: {$jtNoa} NOA, OS ± Rp " . number_format($jtOs, 0, ',', '.') . ".";
            }

            $signalBounce = (bool)($bounce['signal_bounce_risk'] ?? false);
            if ($signalBounce) {
                $risk[] = "Sinyal bounce-back: L0 naik & LT turun, tetapi ada JT dekat.";
            }
        }

        return compact('good', 'bad', 'why', 'risk');
    }

    public function planToday(Request $request)
    {
        $user = $request->user();
        abort_if(!$user, 401);

        $data = $request->validate([
            'account_no'      => ['required', 'string', 'max:30'],
            'nama_nasabah'    => ['nullable', 'string', 'max:255'],
            'kolektibilitas'  => ['nullable', 'string', 'max:50'],
            'jenis_kegiatan'  => ['nullable', 'string', 'max:255'],
            'tujuan_kegiatan' => ['nullable', 'string', 'max:255'],
        ]);

        $today = now()->toDateString();

        $rkhId = DB::table('rkh_headers')->where([
            ['user_id', '=', $user->id],
            ['tanggal', '=', $today],
        ])->value('id');

        if (!$rkhId) {
            $rkhId = DB::table('rkh_headers')->insertGetId([
                'user_id'    => $user->id,
                'tanggal'    => $today,
                'total_jam'  => 0,
                'status'     => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $exists = DB::table('rkh_details')->where([
            ['rkh_id', '=', $rkhId],
            ['account_no', '=', $data['account_no']],
        ])->exists();

        if (!$exists) {
            DB::table('rkh_details')->insert([
                'rkh_id'          => $rkhId,
                'account_no'      => $data['account_no'],
                'nama_nasabah'    => $data['nama_nasabah'] ?? null,
                'kolektibilitas'  => $data['kolektibilitas'] ?? 'LT',
                'jenis_kegiatan'  => $data['jenis_kegiatan'] ?? 'Visit',
                'tujuan_kegiatan' => $data['tujuan_kegiatan'] ?? 'Penagihan / Monitoring',
                'jam_mulai'       => now()->format('H:i:s'),
                'jam_selesai'     => now()->format('H:i:s'),
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }

        return response()->json([
            'ok'              => true,
            'planned_today'   => true,
            'plan_visit_date' => $today,
            'locked'          => false,
        ]);
    }

    private function pushPlannedToRkh(int $userId, string $acc, string $date): void
    {
        $headerId = DB::table('rkh_headers')
            ->where('user_id', $userId)
            ->whereDate('tanggal', $date)
            ->value('id');

        if (!$headerId) {
            $headerId = DB::table('rkh_headers')->insertGetId([
                'user_id'    => $userId,
                'tanggal'    => $date,
                'status'     => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // pakai rkh_id agar konsisten dengan struktur yg dipakai planToday()
        $exists = DB::table('rkh_details')
            ->where('rkh_id', $headerId)
            ->where('account_no', $acc)
            ->exists();

        if (!$exists) {
            DB::table('rkh_details')->insert([
                'rkh_id'          => $headerId,
                'account_no'      => $acc,
                'jenis_kegiatan'  => 'Visit',
                'tujuan_kegiatan' => 'Penagihan / Monitoring',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    /**
     * ============================================================
     * HELPERS
     * ============================================================
     */

    private function normalizeAoCode(?string $ao): ?string
    {
        $ao = trim((string)$ao);
        if ($ao === '') {
            return null;
        }

        $ao = preg_replace('/\s+/', '', $ao);
        $ao = str_pad($ao, 6, '0', STR_PAD_LEFT);

        return $ao === '000000' ? null : $ao;
    }

    private function applyAoScope($query, string $column, array $aoCodes): void
    {
        $aoCodes = collect($aoCodes)
            ->map(fn ($x) => $this->normalizeAoCode($x))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (empty($aoCodes)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where(function ($w) use ($column, $aoCodes) {
            foreach ($aoCodes as $ao) {
                $w->orWhereRaw("LPAD(TRIM({$column}),6,'0') = ?", [$ao]);
            }
        });
    }

    private function resolveTlfeScopeMembers($leader, Carbon $asOf): Collection
    {
        $asOfDate = $asOf->toDateString();

        $q = DB::table('org_assignments as oa')
            ->join('users as u', 'u.id', '=', 'oa.user_id')
            ->selectRaw("
                u.id as user_id,
                u.name as name,
                LPAD(TRIM(u.ao_code),6,'0') as ao_code,
                UPPER(TRIM(COALESCE(u.level, ''))) as level
            ")
            ->where('oa.leader_id', $leader->id)
            ->where('oa.is_active', 1)
            ->whereDate('oa.effective_from', '<=', $asOfDate)
            ->where(function ($w) use ($asOfDate) {
                $w->whereNull('oa.effective_to')
                ->orWhereDate('oa.effective_to', '>=', $asOfDate);
            })
            ->whereNotNull('u.ao_code')
            ->whereRaw("TRIM(COALESCE(u.ao_code,'')) <> ''")
            ->whereRaw("UPPER(TRIM(COALESCE(u.level,''))) = 'FE'");

        return $q
            ->orderBy('u.name')
            ->get()
            ->map(function ($r) {
                return [
                    'user_id' => (int)($r->user_id ?? 0),
                    'name'    => (string)($r->name ?? ''),
                    'ao_code' => $this->normalizeAoCode($r->ao_code ?? null),
                    'level'   => (string)($r->level ?? ''),
                ];
            })
            ->filter(fn ($r) => !empty($r['ao_code']))
            ->unique('ao_code')
            ->values();
    }
}