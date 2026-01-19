@php 
    // ✅ Ambil status dari parent (lebih sinkron untuk SOMASI), fallback ke action->status
    $status = strtolower((string)($headerStatus ?? $action->status ?? ''));
    $status = trim($status);

    // Label default
    $statusLabel = strtoupper($status ?: '-');

    // (Opsional) label lebih manusiawi untuk somasi
    $labels = [
        'draft'      => 'DRAFT',
        'sent'       => 'SENT',
        'submitted'  => 'SENT',
        'kirim'      => 'SENT',
        'received'   => 'RECEIVED',
        'diterima'   => 'RECEIVED',
        'waiting'    => 'WAITING',
        'menunggu'   => 'WAITING',
        'completed'  => 'COMPLETED',
        'selesai'    => 'COMPLETED',
        'failed'     => 'FAILED',
        'cancelled'  => 'CANCELLED',

        // status umum (non-somasi)
        'open'        => 'OPEN',
        'in_progress' => 'IN PROGRESS',
        'done'        => 'DONE',
        'rejected'    => 'REJECTED',
    ];

    $statusLabel = $labels[$status] ?? strtoupper($status ?: '-');

    // ✅ Badge color mapping (ditambah untuk somasi)
    $badge = match ($status) {
        // somasi / workflow
        'draft'       => 'bg-slate-100 text-slate-700 ring-slate-200',
        'sent', 'submitted', 'kirim' => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
        'received', 'diterima' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'waiting', 'menunggu'  => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
        'completed', 'selesai' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'failed'      => 'bg-rose-50 text-rose-700 ring-rose-200',
        'cancelled'   => 'bg-slate-50 text-slate-600 ring-slate-200',

        // non-somasi legacy mapping kamu
        'open'        => 'bg-blue-50 text-blue-700 ring-blue-200',
        'in_progress' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'done'        => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'rejected'    => 'bg-rose-50 text-rose-700 ring-rose-200',

        default       => 'bg-slate-100 text-slate-700 ring-slate-200',
    };
@endphp

@php
    $tooltipUpdateStatus = $tooltipUpdateStatus
        ?? 'Update status. Untuk SOMASI: status selesai ditentukan otomatis setelah Respon Debitur (Step 4).';

    $isSomasiLocked = $isSomasiLocked ?? false;
@endphp

{{-- optional: supaya x-cloak tidak "kedip" --}}
<style>[x-cloak]{ display:none !important; }</style>

<div class="mb-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">

        {{-- KIRI: Judul + badge + ringkasan --}}
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="truncate text-xl font-bold text-slate-900">
                    Detail Legal Action
                </h1>

                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 {{ $badge }}">
                    {{ $statusLabel }}
                </span>
            </div>

            <div class="mt-2 text-sm text-slate-600">
                <div class="flex flex-wrap gap-x-6 gap-y-1">
                    <div>
                        <span class="text-slate-500">Kasus:</span>
                        <span class="font-semibold text-slate-800">
                            {{ $action->legalCase?->legal_case_no ?? '-' }}
                        </span>
                    </div>

                    <div>
                        <span class="text-slate-500">Debitur:</span>
                        <span class="font-semibold text-slate-800">
                            {{ $action->legalCase?->nplCase?->loanAccount?->customer_name ?? '-' }}
                        </span>
                    </div>

                    <div>
                        <span class="text-slate-500">External Ref:</span>
                        <span class="font-semibold text-slate-800">
                            {{ $action->external_ref_no ?? '-' }}
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- KANAN: Dropdown Update Status --}}
        @can('update', $action)
            <div x-data="{ open:false, note:'' }" class="relative">
                <div class="flex items-center gap-2">
                    <button type="button"
                        @click="open = !open"
                        title="{{ $tooltipUpdateStatus }}"
                        class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">
                        🔄 Update Status
                        <span class="text-white/80">▼</span>
                    </button>

                    <button type="button"
                        @click="$dispatch('open-edit-legal-action')"
                        class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold">
                        ✏️ Edit Data
                    </button>
                </div>

                {{-- PANEL dropdown --}}
                <div x-show="open" x-cloak
                    @click.outside="open = false"
                    @keydown.escape.window="open = false"
                    @click.stop
                    class="absolute right-0 mt-2 w-80 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-lg">

                    <div class="px-3 py-2">
                        <div class="text-xs font-semibold text-slate-500">Catatan (opsional)</div>

                        <textarea x-model="note"
                            @click.stop
                            @keydown.stop
                            class="mt-1 w-full rounded-lg border border-slate-200 p-2 text-sm text-slate-700 focus:border-indigo-500 focus:ring-indigo-500"
                            rows="2"
                            placeholder="Contoh: menunggu dokumen, sudah koordinasi dengan lembaga, dll"></textarea>

                        @error('remarks')
                            <div class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</div>
                        @enderror

                        @error('to_status')
                            <div class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="border-t border-slate-100 px-3 py-2 text-xs font-semibold text-slate-500">
                        Pilih status tujuan
                    </div>
                    @php
                        $isSomasi = (($action->action_type ?? '') === 'somasi');

                        // ✅ untuk somasi: jangan tampilkan COMPLETED di dropdown
                        $allowedForDropdown = $allowed;
                        if ($isSomasi) {
                            $allowedForDropdown = array_values(array_filter($allowed, function ($st) {
                                return strtolower((string)$st) !== 'completed';
                            }));
                        }

                        // tooltip
                        $tooltipUpdateStatus = $isSomasi
                            ? 'Status selesai ditentukan otomatis setelah Respon Debitur'
                            : 'Ubah status action';
                    @endphp

                    @forelse($allowedForDropdown as $to)
                        <form method="POST"
                            action="{{ route('legal-actions.update-status', $action) }}"
                            class="border-t border-slate-100">
                            @csrf

                            <input type="hidden" name="to_status" value="{{ $to }}">
                            <input type="hidden" name="remarks" :value="note">

                            <button type="submit"
                                onclick="return confirm('Ubah status menjadi {{ strtoupper($to) }}?')"
                                class="w-full px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">
                                {{ strtoupper($to) }}
                            </button>
                        </form>
                    @empty
                        <div class="border-t border-slate-100 px-3 py-3 text-sm text-slate-500">
                            Tidak ada transisi status yang tersedia.
                        </div>
                    @endforelse
                </div>
            </div>
        @endcan

    </div>
</div>
