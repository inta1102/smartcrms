@extends('layouts.app')

@section('content')
@php
    // ✅ kompatibel: controller kirim $targets + $filters + $tlSlaDays
    $targets = $targets ?? collect();
    $filters = $filters ?? [];
    $tlSlaDays = $tlSlaDays ?? (int) config('crms.sla.tl_days', 1);

    // helper querystring
    $qs = fn($key, $default = '') => $filters[$key] ?? request()->query($key, $default);

    // SLA checker
    $isOverSla = function($createdAt) use ($tlSlaDays) {
        try {
            return \Illuminate\Support\Carbon::parse($createdAt)->lt(now()->subDays($tlSlaDays));
        } catch (\Throwable $e) {
            return false;
        }
    };

    // styles
    $inputBase = 'mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800
                focus:border-slate-400 focus:ring-2 focus:ring-slate-200 outline-none';
    $selectBase = $inputBase;

    $btnBase = 'inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold transition';
    $btnGhost = $btnBase.' border border-slate-200 bg-white text-slate-700 hover:bg-slate-50';
    $btnDark  = $btnBase.' bg-slate-900 text-white hover:bg-slate-800';

    $chip = fn($tone='slate') => match($tone) {
        'rose'  => 'inline-flex items-center rounded-lg border border-rose-200 bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700',
        'amber' => 'inline-flex items-center rounded-lg border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800',
        'emerald' => 'inline-flex items-center rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700',
        default => 'inline-flex items-center rounded-lg border border-slate-200 bg-slate-50 px-2 py-0.5 text-[11px] font-semibold text-slate-700',
    };

    // status badge
    $badge = function(string $status) {
        $s = strtolower((string)$status);
        return match($s) {
            'pending_tl'  => 'bg-amber-50 text-amber-800 border-amber-200',
            'pending_kasi'=> 'bg-rose-50 text-rose-700 border-rose-200',
            'active'      => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'rejected'    => 'bg-slate-100 text-slate-700 border-slate-200',
            'superseded'  => 'bg-slate-50 text-slate-500 border-slate-200',
            default       => 'bg-slate-50 text-slate-600 border-slate-200',
        };
    };

    $label = fn($s) => match(strtolower((string)$s)) {
        'pending_tl'   => 'Pending TL',
        'pending_kasi' => 'Pending KASI',
        'active'       => 'Active',
        'rejected'     => 'Rejected',
        'superseded'   => 'Superseded',
        default        => ucfirst((string)$s),
    };

    // options sesuai controller TL
    $statusOptions = [
        \App\Models\CaseResolutionTarget::STATUS_PENDING_TL => 'Pending TL',
        'active'      => 'Active',
        'rejected'    => 'Rejected',
        'superseded'  => 'Superseded',
        'pending_kasi'=> 'Pending KASI',
    ];
@endphp

<div class="max-w-7xl mx-auto px-4 py-6"
     x-data="{
        open:false,
        mode:'detail', // detail | approve | reject
        payload:{},
        openModal(m,p){ this.mode=m; this.payload=p; this.open=true; this.$nextTick(()=>document.body.classList.add('overflow-hidden')); },
        closeModal(){ this.open=false; this.$nextTick(()=>document.body.classList.remove('overflow-hidden')); },
     }"
>
    <div class="mb-6">
        <h1 class="text-2xl font-extrabold text-slate-900">Approval Target (TL)</h1>
        <p class="mt-1 text-slate-500">Daftar target penyelesaian yang menunggu persetujuan TL.</p>
    </div>

    {{-- FILTER --}}
    <form class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm mb-6" method="get">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-3 items-end">
            <div class="lg:col-span-3">
                <label class="text-xs font-semibold text-slate-600">Status</label>
                <select name="status" class="{{ $selectBase }}">
                    @php $stSelected = (string) $qs('status', \App\Models\CaseResolutionTarget::STATUS_PENDING_TL); @endphp
                    @foreach($statusOptions as $st => $text)
                        <option value="{{ $st }}" @selected($stSelected === (string)$st)>{{ $text }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-5">
                <label class="text-xs font-semibold text-slate-600">Cari (Debitur / AO / Strategi)</label>
                <input name="q" value="{{ $qs('q','') }}" class="{{ $inputBase }}" placeholder="misal: SUROJO / helmi / lelang...">
            </div>

            <div class="lg:col-span-2">
                <label class="text-xs font-semibold text-slate-600">Per halaman</label>
                <select name="per_page" class="{{ $selectBase }}">
                    @foreach([15,20,25,50] as $pp)
                        <option value="{{ $pp }}" @selected((int)$qs('per_page',20) === $pp)>{{ $pp }}</option>
                    @endforeach
                </select>
            </div>

            <div class="lg:col-span-2">
                <div class="flex flex-col gap-2 lg:items-end">
                    <label class="inline-flex items-center gap-2 text-sm text-slate-700 lg:justify-end">
                        <input type="checkbox" name="over_sla" value="1"
                               class="h-4 w-4 rounded border-slate-300 text-slate-900 focus:ring-slate-200"
                               @checked((int)$qs('over_sla',0) === 1)
                        >
                        <span class="whitespace-nowrap">Only over SLA</span>
                    </label>

                    <div class="flex gap-2 w-full lg:w-auto lg:justify-end">
                        <a href="{{ route('supervision.tl.approvals.targets.index') }}" class="{{ $btnGhost }} w-1/2 lg:w-auto">Reset</a>
                        <button class="{{ $btnDark }} w-1/2 lg:w-auto">Terapkan</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    {{-- LIST --}}
    <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="text-left px-4 py-3">Debitur</th>
                        <th class="text-left px-4 py-3">AO / Pengusul</th>
                        <th class="text-left px-4 py-3">Strategi</th>
                        <th class="text-left px-4 py-3">Target</th>
                        <th class="text-left px-4 py-3">Status</th>
                        <th class="text-left px-4 py-3">Created</th>
                        <th class="text-right px-4 py-3">Aksi</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100">
                @forelse($targets as $t)
                    @php
                        // Sesuaikan relasi: controller TL pakai with(['nplCase.loanAccount', 'proposer'])
                        $case = $t->nplCase ?? $t->case ?? null;
                        $loan = $case?->loanAccount;

                        $debtor = $loan?->customer_name ?? $case?->debtor_name ?? '-';
                        $ao     = $loan?->ao_name ?? ($t->proposer?->name ?? $t->proposer?->username ?? '-');

                        $st     = strtolower((string) $t->status);
                        $over   = $isOverSla($t->created_at);

                        // TL seharusnya hanya aksi utk pending_tl
                        $canAct = ($st === \App\Models\CaseResolutionTarget::STATUS_PENDING_TL);

                        $payload = [
                            'id' => $t->id,
                            'case_id' => $t->npl_case_id,
                            'debtor' => $debtor,
                            'ao' => $ao,
                            'status' => (string) $t->status,
                            'status_label' => $label((string)$t->status),
                            'created_at' => optional($t->created_at)->format('d M Y H:i'),
                            'strategy' => (string) ($t->strategy ?? '-'),
                            'reason' => (string) ($t->reason ?? '-'),
                            'target_date' => $t->target_date?->format('d M Y') ?? (string)($t->target_date ?? '-'),
                            'target_outcome' => (string) ($t->target_outcome ?? '-'),
                            'detail_url' => route('cases.show', $t->npl_case_id),
                            'approve_url' => route('supervision.tl.approvals.targets.approve', $t),
                            'reject_url' => route('supervision.tl.approvals.targets.reject', $t),
                        ];
                    @endphp

                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 font-semibold text-slate-900">
                            <a class="hover:underline" href="{{ route('cases.show', $t->npl_case_id) }}">
                                {{ $debtor }}
                            </a>

                            <div class="mt-2 flex flex-wrap gap-2">
                                @if($over)
                                    <span class="{{ $chip('rose') }}">Over SLA</span>
                                @endif
                            </div>
                        </td>

                        <td class="px-4 py-3 text-slate-700">
                            <div class="font-semibold">{{ $ao }}</div>
                            <div class="text-xs text-slate-400">
                                Pengusul: {{ $t->proposer?->name ?? $t->proposer?->username ?? '-' }}
                            </div>
                        </td>

                        <td class="px-4 py-3 text-slate-700">
                            <div class="line-clamp-2">{{ $t->strategy ?? '-' }}</div>
                            @if(!empty($t->reason))
                                <div class="mt-1 text-xs text-slate-400 line-clamp-1">Alasan: {{ $t->reason }}</div>
                            @endif
                        </td>

                        <td class="px-4 py-3 text-slate-700">
                            <div class="font-semibold">{{ $t->target_date?->format('d M Y') ?? (string)($t->target_date ?? '-') }}</div>
                            <div class="text-xs text-slate-400 line-clamp-1">{{ $t->target_outcome ?? '-' }}</div>
                        </td>

                        <td class="px-4 py-3">
                            <span class="inline-flex items-center rounded-lg border px-2 py-1 text-xs font-semibold {{ $badge((string)$t->status) }}">
                                {{ $label($t->status) }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-slate-600">
                            <div>{{ optional($t->created_at)->format('d M Y H:i') }}</div>
                            <div class="text-xs text-slate-400">SLA: {{ $tlSlaDays }} hari</div>
                        </td>

                        <td class="px-4 py-3 text-right">
                            @if($canAct)
                                <div class="inline-flex flex-wrap gap-2 justify-end">
                                    <button type="button"
                                            class="rounded-xl border border-slate-200 px-3 py-1.5 text-xs hover:bg-slate-50"
                                            @click='openModal("detail", @json($payload))'>
                                        Detail
                                    </button>

                                    <button type="button"
                                            class="rounded-xl bg-slate-900 text-white px-3 py-1.5 text-xs hover:bg-slate-800"
                                            @click='openModal("approve", @json($payload))'>
                                        Approve
                                    </button>

                                    <button type="button"
                                            class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 px-3 py-1.5 text-xs hover:bg-rose-100"
                                            @click='openModal("reject", @json($payload))'>
                                        Reject
                                    </button>
                                </div>
                            @else
                                <span class="text-xs text-slate-400">-</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-slate-400">Tidak ada data.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-4 border-t border-slate-100">
            @if(is_object($targets) && method_exists($targets, 'links'))
                {{ $targets->links() }}
            @endif
        </div>
    </div>

    {{-- MODAL (Detail / Approve / Reject) --}}
    <div x-show="open"
         x-cloak
         class="fixed inset-0 z-50"
         aria-modal="true"
         role="dialog"
         @keydown.escape.window="closeModal()"
    >
        <div class="absolute inset-0 bg-black/40" @click="closeModal()"></div>

        <div class="relative mx-auto w-full max-w-2xl px-4 py-8">
            <div class="rounded-2xl bg-white shadow-xl border border-slate-200 overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-4">
                    <div>
                        <div class="text-sm text-slate-500"
                             x-text="mode==='detail' ? 'Detail Target' : (mode==='approve' ? 'Approve Target' : 'Reject Target')"></div>
                        <div class="text-lg font-extrabold text-slate-900" x-text="payload.debtor ?? '-'"></div>
                        <div class="mt-1 text-xs text-slate-500">
                            <span class="font-semibold">AO:</span> <span x-text="payload.ao ?? '-'"></span>
                            <span class="mx-2">•</span>
                            <span class="font-semibold">Created:</span> <span x-text="payload.created_at ?? '-'"></span>
                        </div>
                    </div>

                    <button type="button"
                            class="rounded-xl border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50"
                            @click="closeModal()">
                        ✕
                    </button>
                </div>

                <div class="px-5 py-5 space-y-4">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <div class="text-sm font-semibold text-slate-900">
                                Status:
                                <span class="ml-1 inline-flex items-center rounded-lg border px-2 py-1 text-xs font-semibold"
                                      :class="(payload.status ?? '') === 'pending_tl'
                                                ? 'border-amber-200 bg-amber-50 text-amber-800'
                                                : ((payload.status ?? '') === 'active'
                                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                    : 'border-slate-200 bg-white text-slate-700')"
                                      x-text="payload.status_label ?? '-'"></span>
                            </div>

                            <a class="text-sm font-semibold text-slate-700 hover:underline"
                               :href="payload.detail_url"
                               target="_blank" rel="noopener">
                                Buka Case →
                            </a>
                        </div>

                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <div class="text-xs font-semibold text-slate-600">Strategi</div>
                                <div class="mt-1 text-sm text-slate-800 whitespace-pre-line" x-text="payload.strategy ?? '-'"></div>
                            </div>
                            <div>
                                <div class="text-xs font-semibold text-slate-600">Target</div>
                                <div class="mt-1 text-sm text-slate-800">
                                    <div class="font-semibold" x-text="payload.target_date ?? '-'"></div>
                                    <div class="text-xs text-slate-600 whitespace-pre-line" x-text="payload.target_outcome ?? '-'"></div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <div class="text-xs font-semibold text-slate-600">Alasan / Background</div>
                            <div class="mt-1 text-sm text-slate-700 whitespace-pre-line" x-text="payload.reason ?? '-'"></div>
                        </div>
                    </div>

                    {{-- Detail --}}
                    <template x-if="mode==='detail'">
                        <div class="flex flex-col sm:flex-row gap-2 justify-end">
                            <button type="button"
                                    class="rounded-xl border border-slate-200 px-4 py-2 text-sm hover:bg-slate-50"
                                    @click="closeModal()">
                                Tutup
                            </button>
                            <button type="button"
                                    class="rounded-xl bg-slate-900 text-white px-4 py-2 text-sm hover:bg-slate-800"
                                    @click="mode='approve'">
                                Lanjut Approve
                            </button>
                            <button type="button"
                                    class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 px-4 py-2 text-sm hover:bg-rose-100"
                                    @click="mode='reject'">
                                Lanjut Reject
                            </button>
                        </div>
                    </template>

                    {{-- Approve --}}
                    <template x-if="mode==='approve'">
                        <form method="post" :action="payload.approve_url" class="space-y-4">
                            @csrf
                            <div>
                                <label class="text-xs font-semibold text-slate-600">Catatan Approve (opsional)</label>
                                <textarea name="notes" rows="3" class="{{ $inputBase }}"
                                          placeholder="misal: setujui, minta update progress mingguan / lampirkan bukti komunikasi..."></textarea>
                                <div class="mt-1 text-xs text-slate-500">
                                    Approve TL akan meneruskan target ke antrian KASI.
                                </div>
                            </div>

                            <div class="flex flex-col sm:flex-row gap-2 justify-end">
                                <button type="button"
                                        class="rounded-xl border border-slate-200 px-4 py-2 text-sm hover:bg-slate-50"
                                        @click="mode='detail'">
                                    Kembali
                                </button>

                                <button type="button"
                                        class="rounded-xl border border-slate-200 px-4 py-2 text-sm hover:bg-slate-50"
                                        @click="closeModal()">
                                    Batal
                                </button>

                                <button class="rounded-xl bg-slate-900 text-white px-4 py-2 text-sm hover:bg-slate-800"
                                        onclick="return confirm('Approve target ini?');">
                                    Approve
                                </button>
                            </div>
                        </form>
                    </template>

                    {{-- Reject --}}
                    <template x-if="mode==='reject'">
                        <form method="post" :action="payload.reject_url" class="space-y-4">
                            @csrf
                            <div>
                                <label class="text-xs font-semibold text-slate-600">Alasan Reject (wajib)</label>
                                <textarea name="reason" rows="4" class="{{ $inputBase }}" required
                                          placeholder="Tuliskan alasan jelas (audit-friendly). Contoh: Strategi belum realistis; minta revisi rencana; jadwal visit; bukti komunikasi..."></textarea>
                                <div class="mt-1 text-xs text-slate-500">
                                    Pastikan alasan spesifik: apa yang salah + apa yang harus diperbaiki.
                                </div>
                            </div>

                            <div class="flex flex-col sm:flex-row gap-2 justify-end">
                                <button type="button"
                                        class="rounded-xl border border-slate-200 px-4 py-2 text-sm hover:bg-slate-50"
                                        @click="mode='detail'">
                                    Kembali
                                </button>

                                <button type="button"
                                        class="rounded-xl border border-slate-200 px-4 py-2 text-sm hover:bg-slate-50"
                                        @click="closeModal()">
                                    Batal
                                </button>

                                <button class="rounded-xl border border-rose-200 bg-rose-50 text-rose-700 px-4 py-2 text-sm hover:bg-rose-100"
                                        onclick="return confirm('Reject target ini?');">
                                    Reject
                                </button>
                            </div>
                        </form>
                    </template>

                </div>
            </div>
        </div>
    </div>

</div>
@endsection
