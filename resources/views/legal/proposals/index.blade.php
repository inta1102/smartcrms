@extends('layouts.app')

@section('content')
<div class="space-y-5"
     x-data="proposalApproveModal()"
     x-init="init()">

    <h1 class="text-2xl font-extrabold text-slate-900">📑 Legal Action Proposals</h1>

    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                    <th class="px-4 py-3">Debitur</th>
                    <th class="px-4 py-3">CIF</th>
                    <th class="px-4 py-3">Jenis</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Pengusul</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </thead>

            <tbody class="divide-y">
            @forelse($proposals as $p)
                @php
                    $loan = $p->nplCase?->loanAccount;

                    $status = $p->status;

                    $statusClass = match ($status) {
                        \App\Models\LegalActionProposal::STATUS_PENDING_TL     => 'bg-amber-100 text-amber-700',
                        \App\Models\LegalActionProposal::STATUS_APPROVED_TL,
                        \App\Models\LegalActionProposal::STATUS_PENDING_KASI   => 'bg-indigo-100 text-indigo-700',
                        \App\Models\LegalActionProposal::STATUS_APPROVED_KASI  => 'bg-emerald-100 text-emerald-700',
                        \App\Models\LegalActionProposal::STATUS_EXECUTED       => 'bg-slate-200 text-slate-700',
                        \App\Models\LegalActionProposal::STATUS_REJECTED_TL,
                        \App\Models\LegalActionProposal::STATUS_REJECTED_KASI  => 'bg-rose-100 text-rose-700',
                        default                                                => 'bg-slate-100 text-slate-700',
                    };

                    $statusText = strtoupper(str_replace('_',' ', $status));

                    $canApproveTl = auth()->user()->hasAnyRole(['TL','TLL','TLR','TLRO','TLSO','TLFE','TLBE','TLUM'])
                        && $status === \App\Models\LegalActionProposal::STATUS_PENDING_TL;

                    // ✅ Kasi hanya approve dari approved_tl (antrian Kasi)
                    $canApproveKasi = auth()->user()->hasAnyRole(['KSLU','KSLR','KSFE','KSBE'])
                        && $status === \App\Models\LegalActionProposal::STATUS_APPROVED_TL;

                    $canExecute = auth()->user()->hasAnyRole(['BE'])
                        && $status === \App\Models\LegalActionProposal::STATUS_APPROVED_KASI;
                @endphp

                <tr>
                    <td class="px-4 py-3 font-semibold">{{ $loan->customer_name ?? '-' }}</td>
                    <td class="px-4 py-3">{{ $loan->cif ?? '-' }}</td>

                    <td class="px-4 py-3">
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                            {{ strtoupper($p->action_type) }}
                        </span>
                    </td>

                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $statusClass }}">
                            {{ $statusText }}
                        </span>
                    </td>

                    <td class="px-4 py-3">{{ $p->proposer?->name ?? '-' }}</td>

                    <td class="px-4 py-3 text-right">
                        <button type="button"
                                @click="open({
                                    id: {{ (int)$p->id }},
                                    customer_name: @js($loan->customer_name ?? '-'),
                                    cif: @js($loan->cif ?? '-'),
                                    action_type: @js(strtoupper($p->action_type)),
                                    status: @js($statusText),
                                    proposer: @js($p->proposer?->name ?? '-'),
                                    created_at: @js(optional($p->created_at)->format('d/m/Y H:i')),
                                    reason: @js($p->reason),
                                    notes: @js($p->notes),
                                    approved_tl_notes: @js($p->approved_tl_notes ?? null),
                                    approved_kasi_notes: @js($p->approved_kasi_notes ?? null),

                                    canApproveTl: {{ $canApproveTl ? 'true' : 'false' }},
                                    canApproveKasi: {{ $canApproveKasi ? 'true' : 'false' }},
                                    canExecute: {{ $canExecute ? 'true' : 'false' }},

                                    approveTlUrl: @js(route('legal.proposals.approve-tl', $p)),
                                    approveKasiUrl: @js(route('legal.proposals.approve-kasi', $p)),
                                    executeUrl: @js(route('legal.proposals.execute', $p)),  {{-- kalau route ini ada --}}
                                })"
                                class="inline-flex items-center gap-1 rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-semibold hover:bg-slate-200">
                            👁 Detail
                        </button>
                    </td>
                </tr>

            @empty
                <tr>
                    <td colspan="6" class="px-6 py-10 text-center text-slate-500">
                        Tidak ada proposal.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $proposals->links() }}

    {{-- ==========================
        MODAL DETAIL + APPROVE (SINGLE SOURCE OF TRUTH)
    =========================== --}}
    <div x-show="show" x-cloak class="fixed inset-0 z-40 bg-black/40"
         @click="close()"></div>

    <div x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
         @keydown.escape.window="close()">
        <div class="w-full max-w-2xl rounded-2xl bg-white shadow-xl overflow-hidden">
            <div class="border-b border-slate-200 px-5 py-4 flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-lg font-bold text-slate-900">Detail Usulan Legal</h3>
                    <p class="text-sm text-slate-500">
                        Debitur: <span class="font-semibold text-slate-700" x-text="p.customer_name"></span>
                        · CIF: <span class="font-semibold text-slate-700" x-text="p.cif"></span>
                    </p>
                </div>
                <button type="button" @click="close()"
                        class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm hover:bg-slate-50">
                    ✕
                </button>
            </div>

            <div class="px-5 py-4 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="rounded-xl border border-slate-200 p-3">
                        <div class="text-xs text-slate-500">Jenis</div>
                        <div class="font-bold uppercase" x-text="p.action_type"></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-3">
                        <div class="text-xs text-slate-500">Status</div>
                        <div class="font-bold" x-text="p.status"></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-3">
                        <div class="text-xs text-slate-500">Pengusul</div>
                        <div class="font-semibold" x-text="p.proposer"></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-3">
                        <div class="text-xs text-slate-500">Dibuat</div>
                        <div class="font-semibold" x-text="p.created_at"></div>
                    </div>
                </div>

                <div class="rounded-xl border border-slate-200 p-3">
                    <div class="text-xs text-slate-500 mb-1">Alasan (Reason)</div>
                    <div class="text-sm text-slate-800 whitespace-pre-wrap" x-text="p.reason || '-'"></div>
                </div>

                <div class="rounded-xl border border-slate-200 p-3">
                    <div class="text-xs text-slate-500 mb-1">Catatan Pengusul (Notes)</div>
                    <div class="text-sm text-slate-800 whitespace-pre-wrap" x-text="p.notes || '-'"></div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="rounded-xl border border-slate-200 p-3">
                        <div class="text-xs text-slate-500 mb-1">Catatan TL</div>
                        <div class="text-sm text-slate-800 whitespace-pre-wrap" x-text="p.approved_tl_notes || '-'"></div>
                    </div>
                    <div class="rounded-xl border border-slate-200 p-3">
                        <div class="text-xs text-slate-500 mb-1">Catatan Kasi</div>
                        <div class="text-sm text-slate-800 whitespace-pre-wrap" x-text="p.approved_kasi_notes || '-'"></div>
                    </div>
                </div>

                <div x-show="p.canApproveTl || p.canApproveKasi" class="rounded-xl border border-indigo-200 bg-indigo-50/40 p-3">
                    <div class="text-sm font-semibold text-slate-900">Catatan Approver</div>
                    <p class="text-xs text-slate-600 mt-0.5">
                        Isi catatan singkat sebelum approve (wajib).
                    </p>
                    <textarea x-model="approvalNotes" rows="3"
                        class="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                        placeholder="Contoh: Disetujui. Pastikan data agunan lengkap / SP3 sudah ada / dll"></textarea>
                </div>
            </div>

            <div class="border-t border-slate-200 px-5 py-4 flex flex-col sm:flex-row gap-2 sm:justify-end">
                <button type="button" @click="close()"
                    class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    Tutup
                </button>

                <form x-show="p.canApproveTl" method="POST" :action="p.approveTlUrl" class="inline">
                    @csrf
                    <input type="hidden" name="approval_notes" :value="approvalNotes">
                    <button type="submit"
                        :disabled="!approvalNotes.trim()"
                        class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                        ✅ Approve TL
                    </button>
                </form>

                <form x-show="p.canApproveKasi" method="POST" :action="p.approveKasiUrl" class="inline">
                    @csrf
                    <input type="hidden" name="approval_notes" :value="approvalNotes">
                    <button type="submit"
                        :disabled="!approvalNotes.trim()"
                        class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                        ✅ Approve Kasi
                    </button>
                </form>

                <form x-show="p.canExecute" method="POST" :action="p.executeUrl" class="inline">
                    @csrf
                    <button type="submit"
                        class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
                        ⚙️ Eksekusi (BE)
                    </button>
                </form>

            </div>
        </div>
    </div>

</div>

<script>
function proposalApproveModal() {
    return {
        show: false,
        approvalNotes: '',
        p: {
            id: null,
        },

        init() {},

        open(payload) {
            this.p = payload;
            this.show = true;
            this.approvalNotes = '';
        },

        close() {
            this.show = false;
            this.p = { id: null };
            this.approvalNotes = '';
        },

        // ✅ URL execute dibentuk DINAMIS dari p.id
        get executeUrl() {
            if (!this.p?.id) return '#';

            // base URL dari Blade, TANPA parameter
            const base = @js(route('legal.proposals.execute', ['proposal' => '__ID__']));

            return base.replace('__ID__', this.p.id);
        }
    }
}
</script>

@endsection
