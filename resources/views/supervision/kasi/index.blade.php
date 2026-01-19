@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto px-4 py-6">

    <div class="mb-6">
        <h1 class="text-2xl font-extrabold text-slate-900">Dashboard Supervisi KASI</h1>
        <p class="mt-1 text-slate-500">
            Fokus KASI: keputusan (approval), SLA, pola risiko, dan kualitas eksekusi. Bukan operasional harian.
        </p>
    </div>

    {{-- ===================== --}}
    {{-- DECISION PANEL (KASI) --}}
    {{-- ===================== --}}
    @php
        // Ekspektasi data dari service:
        // $decisionPanel = [
        //   'pending_tl' => ['count'=>..,'over_sla'=>..,'sla_days'=>..],
        //   'pending_kasi' => ['count'=>..,'over_sla'=>..,'sla_days'=>..,'avg_age_hours'=>..],
        //   'sla_breach_total' => ['count'=>..,'breakdown'=>['tl'=>..,'kasi'=>..]],
        //   'rejected_7d' => ['count'=>..],
        // ];

        $dp = $decisionPanel ?? [];

        $tile = function(string $title, $val, ?string $sub = null, ?string $href = null, string $tone = 'default') {
            $toneClass = match ($tone) {
                'danger'  => 'border-rose-200 bg-rose-50',
                'warning' => 'border-amber-200 bg-amber-50',
                'info'    => 'border-sky-200 bg-sky-50',
                default   => 'border-slate-200 bg-white',
            };

            $wrapStart = $href
                ? '<a href="'.$href.'" class="block rounded-2xl border '.$toneClass.' p-4 shadow-sm hover:shadow transition">'
                : '<div class="rounded-2xl border '.$toneClass.' p-4 shadow-sm">';

            $wrapEnd = $href ? '</a>' : '</div>';

            return $wrapStart.'
                <div class="text-sm text-slate-600">'.$title.'</div>
                <div class="mt-1 text-3xl font-extrabold text-slate-900">'.(int)$val.'</div>
                '.($sub ? '<div class="mt-1 text-xs text-slate-500">'.$sub.'</div>' : '').'
            '.$wrapEnd;
        };

        // ===== Link aksi (pakai route yang sudah kamu punya) =====
        // NOTE: untuk mencegah error kalau route belum ada, pakai helper try/catch kecil.
        $safeRoute = function(string $name, array $params = []) {
            try {
                return route($name, $params);
            } catch (\Throwable $e) {
                return null;
            }
        };

        // Pending TL -> tetap diarahkan ke list approval targets (pakai filter status pending_tl)
        $hrefPendingTl = $safeRoute(
            'supervision.kasi.approvals.targets.index',
            ['status' => \App\Models\CaseResolutionTarget::STATUS_PENDING_TL]
        );

        $hrefPendingKasi = $safeRoute(
            'supervision.kasi.approvals.targets.index',
            ['status' => \App\Models\CaseResolutionTarget::STATUS_PENDING_KASI]
        );

        $hrefSlaBreach = $safeRoute(
            'supervision.kasi.approvals.targets.index',
            ['over_sla' => 1]
        );

        $hrefRejected7d = $safeRoute(
            'supervision.kasi.approvals.targets.index',
            ['status' => 'rejected', 'range' => '7d']
        );

        // ===== Values =====
        $pendingTlCount       = (int)($dp['pending_tl']['count'] ?? 0);
        $pendingTlOverSla     = (int)($dp['pending_tl']['over_sla'] ?? 0);
        $pendingTlSlaDays     = (int)($dp['pending_tl']['sla_days'] ?? 2);

        $pendingKasiCount     = (int)($dp['pending_kasi']['count'] ?? 0);
        $pendingKasiOverSla   = (int)($dp['pending_kasi']['over_sla'] ?? 0);
        $pendingKasiSlaDays   = (int)($dp['pending_kasi']['sla_days'] ?? 2);
        $pendingKasiAvgHours  = (int)($dp['pending_kasi']['avg_age_hours'] ?? 0);

        $slaBreachTotal       = (int)($dp['sla_breach_total']['count'] ?? 0);
        $slaBreachTl          = (int)($dp['sla_breach_total']['breakdown']['tl'] ?? 0);
        $slaBreachKasi        = (int)($dp['sla_breach_total']['breakdown']['kasi'] ?? 0);

        $rejected7dCount      = (int)($dp['rejected_7d']['count'] ?? 0);

        // ===== Tones =====
        $tonePendingTl   = $pendingTlOverSla > 0 ? 'warning' : 'default';
        $tonePendingKasi = $pendingKasiOverSla > 0 ? 'danger'  : 'default';
        $toneSlaBreach   = $slaBreachTotal > 0 ? 'danger' : 'default';
        $toneRejected7d  = $rejected7dCount > 0 ? 'info' : 'default';

        // ===== Subs =====
        $subPendingTl = $pendingTlOverSla > 0
            ? "Lewat SLA: {$pendingTlOverSla} (SLA {$pendingTlSlaDays} hari)"
            : "SLA {$pendingTlSlaDays} hari";

        $subPendingKasi = $pendingKasiOverSla > 0
            ? "Lewat SLA: {$pendingKasiOverSla} (SLA {$pendingKasiSlaDays} hari) • Rata2 umur: {$pendingKasiAvgHours} jam"
            : "SLA {$pendingKasiSlaDays} hari • Rata2 umur: {$pendingKasiAvgHours} jam";

        $subSla = $slaBreachTotal > 0
            ? "Breakdown: TL {$slaBreachTl} • KASI {$slaBreachKasi}"
            : "Tidak ada pelanggaran SLA";

        $subRejected = "7 hari terakhir";
    @endphp

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        {!! $tile('Pending TL Approval', $pendingTlCount, $subPendingTl, $hrefPendingTl, $tonePendingTl) !!}
        {!! $tile('Pending KASI Approval', $pendingKasiCount, $subPendingKasi, $hrefPendingKasi, $tonePendingKasi) !!}
        {!! $tile('Approval Lewat SLA', $slaBreachTotal, $subSla, $hrefSlaBreach, $toneSlaBreach) !!}
        {!! $tile('Rejected / Revisi', $rejected7dCount, $subRejected, $hrefRejected7d, $toneRejected7d) !!}
    </div>

    {{-- ===================== --}}
    {{-- RISK & CONTROL (KASI) --}}
    {{-- ===================== --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="font-bold text-slate-900">🔥 Overdue berat</div>
            <div class="text-sm text-slate-500 mt-1">Agenda lewat due ≥ 3 hari. Fokus intervensi & eskalasi.</div>
            <div class="mt-3 space-y-2">
                @forelse(($attention['overdue_heavy'] ?? []) as $it)
                    <a class="block rounded-xl border border-slate-100 p-3 hover:bg-slate-50"
                       href="{{ route('cases.show', $it['case_id']) }}">
                        <div class="font-semibold text-slate-900">{{ $it['debtor'] }}</div>
                        <div class="text-xs text-slate-500">{{ $it['agenda_title'] }} • due {{ $it['due'] }}</div>
                    </a>
                @empty
                    <div class="text-sm text-slate-400">Aman, belum ada overdue berat.</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="font-bold text-slate-900">⚠️ In progress tanpa tindakan</div>
            <div class="text-sm text-slate-500 mt-1">Sudah start, belum ada action log. Indikasi eksekusi lemah.</div>
            <div class="mt-3 space-y-2">
                @forelse(($attention['in_progress_empty'] ?? []) as $it)
                    <a class="block rounded-xl border border-slate-100 p-3 hover:bg-slate-50"
                       href="{{ route('cases.show', $it['case_id']) }}">
                        <div class="font-semibold text-slate-900">{{ $it['debtor'] }}</div>
                        <div class="text-xs text-slate-500">{{ $it['agenda_title'] }} • start {{ $it['started_at'] }}</div>
                    </a>
                @empty
                    <div class="text-sm text-slate-400">Aman, tidak ada yang “start tapi kosong”.</div>
                @endforelse
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="font-bold text-slate-900">🕒 Kasus stagnan</div>
            <div class="text-sm text-slate-500 mt-1">Tidak ada tindakan dalam ≥ 7 hari. Fokus koreksi strategi.</div>
            <div class="mt-3 space-y-2">
                @forelse(($attention['stagnant'] ?? []) as $it)
                    <a class="block rounded-xl border border-slate-100 p-3 hover:bg-slate-50"
                       href="{{ route('cases.show', $it['case_id']) }}">
                        <div class="font-semibold text-slate-900">{{ $it['debtor'] }}</div>
                        <div class="text-xs text-slate-500">Last action: {{ $it['last_action_at'] }}</div>
                    </a>
                @empty
                    <div class="text-sm text-slate-400">Aman, tidak ada stagnan.</div>
                @endforelse
            </div>
        </div>

    </div>

    {{-- ===================== --}}
    {{-- EXECUTION QUALITY (RINGKAS) --}}
    {{-- ===================== --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-4 border-b border-slate-100">
            <div class="font-bold text-slate-900">Agenda AO (Ringkas)</div>
            <div class="text-sm text-slate-500">
                Untuk kontrol kualitas eksekusi (bukan micro-manage). Klik debitur untuk detail kasus.
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="text-left px-4 py-3">Debitur</th>
                        <th class="text-left px-4 py-3">AO</th>
                        <th class="text-left px-4 py-3">Agenda</th>
                        <th class="text-left px-4 py-3">Type</th>
                        <th class="text-left px-4 py-3">Status</th>
                        <th class="text-left px-4 py-3">Due</th>
                        <th class="text-left px-4 py-3">Last Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($rows as $r)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-semibold text-slate-900">
                                <a class="hover:underline" href="{{ route('cases.show', $r['case_id']) }}">
                                    {{ $r['debtor'] }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-slate-700">{{ $r['ao_name'] }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ $r['agenda_title'] }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ $r['agenda_type'] }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ $r['status'] }}</td>
                            <td class="px-4 py-3 text-slate-700">{{ $r['due_at'] }}</td>
                            <td class="px-4 py-3 text-slate-500">{{ $r['last_action'] ?? '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-400">Belum ada data.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-4 border-t border-slate-100">
            {{ $rows->links() }}
        </div>
    </div>

</div>
@endsection
