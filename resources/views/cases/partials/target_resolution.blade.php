@php
    $activeTarget = $case->activeResolutionTarget;
    $targets = $case->resolutionTargets()->latest()->get();

    $badgeClass = function (?string $status) {
        $s = strtolower((string) $status);

        return match ($s) {
            'draft'        => 'bg-slate-100 text-slate-700 border-slate-200',
            'pending_tl'   => 'bg-amber-50 text-amber-700 border-amber-200',
            'pending_kasi' => 'bg-sky-50 text-sky-700 border-sky-200',
            'active'       => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'rejected'     => 'bg-rose-50 text-rose-700 border-rose-200',
            'superseded'   => 'bg-slate-200 text-slate-700 border-slate-300',
            default        => 'bg-slate-50 text-slate-600 border-slate-200',
        };
    };

    $prettyOutcome = function (?string $outcome) {
        $o = strtolower((string) $outcome);

        return match ($o) {
            'lunas'  => 'Lunas',
            'lancar' => 'Lancar',
            default  => '-',
        };
    };

    $prettyStrategy = function (?string $strategy) {
        $s = strtolower((string) $strategy);

        return match ($s) {
            'lelang'     => 'Lelang',
            'rs'         => 'Restrukturisasi',
            'ayda'       => 'AYDA',
            'intensif'   => 'Penagihan Intensif',
            default      => $strategy ? ucfirst($strategy) : '-',
        };
    };
@endphp

<div class="space-y-6">

    {{-- ===================== --}}
    {{-- TARGET AKTIF --}}
    {{-- ===================== --}}
    <div class="rounded-xl border bg-white p-5 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-700 mb-3">
            🎯 Target Penyelesaian Aktif
        </h3>

        @if($activeTarget)
            <div class="grid grid-cols-1 md:grid-cols-5 gap-4 text-sm">
                <div>
                    <div class="text-slate-500">Target Tanggal</div>
                    <div class="font-semibold text-slate-900">
                        {{ $activeTarget->target_date?->format('d M Y') ?? '-' }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Target Kondisi</div>
                    <div class="font-semibold text-slate-900">
                        {{ $prettyOutcome($activeTarget->target_outcome ?? null) }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Strategi</div>
                    <div class="font-semibold text-slate-900">
                        {{ $prettyStrategy($activeTarget->strategy ?? null) }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Disetujui Oleh</div>
                    <div class="font-semibold text-slate-900">
                        {{ $activeTarget->approver?->name ?? '-' }}
                    </div>
                </div>

                <div>
                    <div class="text-slate-500">Status</div>
                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {{ $badgeClass($activeTarget->status ?? 'active') }}">
                        {{ strtoupper($activeTarget->status ?? 'ACTIVE') }}
                    </span>
                </div>
            </div>

            {{-- tampilkan notes/catatan --}}
            <div class="mt-3 space-y-1 text-xs text-slate-600">
                @if(!empty($activeTarget->reason))
                    <div class="italic">Catatan AO: {{ $activeTarget->reason }}</div>
                @endif
                @if(!empty($activeTarget->tl_notes))
                    <div class="italic">Catatan TL: {{ $activeTarget->tl_notes }}</div>
                @endif
                @if(!empty($activeTarget->kasi_notes))
                    <div class="italic">Catatan Kasi: {{ $activeTarget->kasi_notes }}</div>
                @endif
            </div>
        @else
            <div class="text-sm text-slate-500 italic">
                Belum ada target penyelesaian aktif.
            </div>
        @endif
    </div>

    {{-- ===================== --}}
    {{-- FORM PROPOSE (AO) --}}
    {{-- ===================== --}}
    @can('propose', [\App\Models\CaseResolutionTarget::class, $case])
        <div id="target-form" class="rounded-xl border bg-white p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-700 mb-3">
                ✍️ Usulkan Target Penyelesaian
            </h3>

            <form method="POST"
                  action="{{ route('npl-cases.resolution-targets.store', $case) }}"
                  class="grid grid-cols-1 md:grid-cols-6 gap-4">
                @csrf

                <div>
                    <label class="text-xs text-slate-600">Target Tanggal</label>
                    <input type="date" name="target_date" required
                           class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                </div>

                <div>
                    <label class="text-xs text-slate-600">Target Kondisi</label>
                    <select name="target_outcome" required
                            class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        <option value="">- pilih -</option>
                        <option value="lunas">Lunas</option>
                        <option value="lancar">Lancar</option>
                    </select>
                </div>

                <div>
                    <label class="text-xs text-slate-600">Strategi</label>
                    <select name="strategy" class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        <option value="">- pilih -</option>
                        <option value="lelang">Lelang</option>
                        <option value="rs">Restrukturisasi</option>
                        <option value="ayda">AYDA</option>
                        <option value="intensif">Penagihan Intensif</option>
                    </select>
                </div>

                <div class="md:col-span-3">
                    <label class="text-xs text-slate-600">Alasan / Catatan AO</label>
                    <input type="text" name="reason"
                           class="mt-1 w-full rounded-lg border-slate-300 text-sm"
                           placeholder="Ringkas & jelas (wajib jika revisi)">
                </div>

                <div class="md:col-span-6">
                    <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        Ajukan Target
                    </button>
                </div>
            </form>
        </div>
    @endcan

    {{-- ===================== --}}
    {{-- DAFTAR TARGET (HISTORI + APPROVAL QUEUE) --}}
    {{-- ===================== --}}
    <div id="target-history" class="rounded-xl border bg-white p-5 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-700 mb-3">
            📚 Riwayat & Approval Target
        </h3>

        @if($targets->isEmpty())
            <div class="text-sm text-slate-500 italic">
                Belum ada riwayat target.
            </div>
        @else

            {{-- ===================== --}}
            {{-- MOBILE: CARD LIST --}}
            {{-- ===================== --}}
            <div class="space-y-3 md:hidden">
                @foreach($targets as $target)
                    <div class="rounded-xl border border-slate-200 p-4"
                        x-data="{ mode: null }">

                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-xs text-slate-500">Target</div>
                                <div class="text-sm font-semibold text-slate-900">
                                    {{ $target->target_date?->format('d M Y') ?? '-' }}
                                </div>
                                <div class="mt-1 text-xs text-slate-600">
                                    Kondisi: <span class="font-semibold">{{ $prettyOutcome($target->target_outcome ?? null) }}</span>
                                    • Strategi: <span class="font-semibold">{{ $prettyStrategy($target->strategy ?? null) }}</span>
                                </div>
                                <div class="mt-1 text-xs text-slate-600">
                                    Diusulkan: <span class="font-semibold">{{ $target->proposer?->name ?? '-' }}</span>
                                </div>
                            </div>

                            <span class="shrink-0 inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {{ $badgeClass($target->status) }}">
                                {{ strtoupper($target->status) }}
                            </span>
                        </div>

                        {{-- Notes ringkas --}}
                        <div class="mt-3 space-y-1 text-[11px] text-slate-500">
                            @if(!empty($target->reason))
                                <div>AO: {{ $target->reason }}</div>
                            @endif
                            @if(!empty($target->tl_notes))
                                <div>TL: {{ $target->tl_notes }}</div>
                            @endif
                            @if(!empty($target->kasi_notes))
                                <div>Kasi: {{ $target->kasi_notes }}</div>
                            @endif
                            @if(!empty($target->reject_reason))
                                <div class="text-rose-600">Reject: {{ $target->reject_reason }}</div>
                            @endif
                        </div>

                        {{-- ACTIONS (Mobile) --}}
                        <div class="mt-4 space-y-2">

                            {{-- BUTTON ROW (saat mode null) --}}
                            <div x-show="mode === null" class="flex flex-wrap gap-2">
                                @can('approveTl', $target)
                                    <button @click="mode='tl'"
                                            class="rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-700">
                                        TL Approve
                                    </button>
                                @endcan

                                @can('approveKasi', $target)
                                    <button @click="mode='kasi'"
                                            class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                                        Kasi Approve
                                    </button>
                                @endcan

                                @can('reject', $target)
                                    <button @click="mode='reject'"
                                            class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700">
                                        Tolak
                                    </button>
                                @endcan
                            </div>

                            {{-- TL FORM --}}
                            @can('approveTl', $target)
                                <div x-show="mode==='tl'" x-transition class="rounded-lg border border-sky-100 bg-sky-50 p-3">
                                    <form method="POST" action="{{ route('resolution-targets.approve-tl', $target) }}" class="space-y-2">
                                        @csrf
                                        <label class="text-xs font-semibold text-slate-700">Catatan TL (opsional)</label>
                                        <textarea name="notes" rows="3"
                                                class="w-full rounded-lg border-slate-300 text-sm"
                                                placeholder="Tulis pertimbangan TL..."></textarea>

                                        <div class="flex gap-2">
                                            <button type="submit"
                                                    class="flex-1 rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-700">
                                                Kirim TL Approve
                                            </button>
                                            <button type="button" @click="mode=null"
                                                    class="rounded-lg bg-white px-3 py-2 text-xs font-semibold text-slate-700 border border-slate-300">
                                                Batal
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            @endcan

                            {{-- KASI FORM --}}
                            @can('approveKasi', $target)
                                <div x-show="mode==='kasi'" x-transition class="rounded-lg border border-emerald-100 bg-emerald-50 p-3">
                                    <form method="POST" action="{{ route('resolution-targets.approve-kasi', $target) }}" class="space-y-2">
                                        @csrf
                                        <label class="text-xs font-semibold text-slate-700">Catatan Kasi (opsional)</label>
                                        <textarea name="notes" rows="3"
                                                class="w-full rounded-lg border-slate-300 text-sm"
                                                placeholder="Tulis pertimbangan Kasi..."></textarea>

                                        <div class="flex gap-2">
                                            <button type="submit"
                                                    class="flex-1 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                                                Kirim Kasi Approve
                                            </button>
                                            <button type="button" @click="mode=null"
                                                    class="rounded-lg bg-white px-3 py-2 text-xs font-semibold text-slate-700 border border-slate-300">
                                                Batal
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            @endcan

                            {{-- REJECT FORM --}}
                            @can('reject', $target)
                                <div x-show="mode==='reject'" x-transition class="rounded-lg border border-rose-100 bg-rose-50 p-3">
                                    <form method="POST" action="{{ route('resolution-targets.reject', $target) }}" class="space-y-2">
                                        @csrf
                                        <label class="text-xs font-semibold text-slate-700">Alasan penolakan (wajib)</label>
                                        <textarea name="reject_reason" rows="3" required
                                                class="w-full rounded-lg border-slate-300 text-sm"
                                                placeholder="Contoh: target tidak realistis / data belum lengkap / strategi belum sesuai..."></textarea>

                                        <div class="flex gap-2">
                                            <button type="submit"
                                                    class="flex-1 rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700">
                                                Kirim Penolakan
                                            </button>
                                            <button type="button" @click="mode=null"
                                                    class="rounded-lg bg-white px-3 py-2 text-xs font-semibold text-slate-700 border border-slate-300">
                                                Batal
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            @endcan

                        </div>
                    </div>
                @endforeach
            </div>

            {{-- ===================== --}}
            {{-- DESKTOP: TABLE --}}
            {{-- ===================== --}}
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                    <tr class="border-b text-slate-500">
                        <th class="py-2 text-left">Target</th>
                        <th class="py-2 text-left">Kondisi</th>
                        <th class="py-2 text-left">Strategi</th>
                        <th class="py-2 text-left">Status</th>
                        <th class="py-2 text-left">Diusulkan</th>
                        <th class="py-2 text-left">Aksi</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($targets as $target)
                        <tr class="border-b align-top" x-data="{ mode: null }">
                            <td class="py-2">{{ $target->target_date?->format('d M Y') ?? '-' }}</td>
                            <td class="py-2">{{ $prettyOutcome($target->target_outcome ?? null) }}</td>
                            <td class="py-2">{{ $prettyStrategy($target->strategy ?? null) }}</td>
                            <td class="py-2">
                                <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold {{ $badgeClass($target->status) }}">
                                    {{ strtoupper($target->status) }}
                                </span>

                                <div class="mt-2 space-y-1 text-[11px] text-slate-500">
                                    @if(!empty($target->reason)) <div>AO: {{ $target->reason }}</div> @endif
                                    @if(!empty($target->tl_notes)) <div>TL: {{ $target->tl_notes }}</div> @endif
                                    @if(!empty($target->kasi_notes)) <div>Kasi: {{ $target->kasi_notes }}</div> @endif
                                    @if(!empty($target->reject_reason)) <div class="text-rose-600">Reject: {{ $target->reject_reason }}</div> @endif
                                </div>
                            </td>
                            <td class="py-2">{{ $target->proposer?->name ?? '-' }}</td>

                            <td class="py-2 w-[320px]">
                                <div class="space-y-2">

                                    {{-- tombol --}}
                                    <div x-show="mode===null" class="flex flex-wrap gap-2">
                                        @can('approveTl', $target)
                                            <button @click="mode='tl'"
                                                    class="rounded bg-sky-600 px-2 py-1 text-xs text-white hover:bg-sky-700">
                                                TL Approve
                                            </button>
                                        @endcan
                                        @can('approveKasi', $target)
                                            <button @click="mode='kasi'"
                                                    class="rounded bg-emerald-600 px-2 py-1 text-xs text-white hover:bg-emerald-700">
                                                Kasi Approve
                                            </button>
                                        @endcan
                                        @can('reject', $target)
                                            <button @click="mode='reject'"
                                                    class="rounded bg-rose-600 px-2 py-1 text-xs text-white hover:bg-rose-700">
                                                Tolak
                                            </button>
                                        @endcan
                                    </div>

                                    {{-- TL --}}
                                    @can('approveTl', $target)
                                        <div x-show="mode==='tl'" x-transition class="rounded-lg border border-sky-100 bg-sky-50 p-3">
                                            <form method="POST" action="{{ route('resolution-targets.approve-tl', $target) }}" class="space-y-2">
                                                @csrf
                                                <textarea name="notes" rows="2"
                                                        class="w-full rounded-lg border-slate-300 text-xs"
                                                        placeholder="Catatan TL (opsional)"></textarea>
                                                <div class="flex gap-2">
                                                    <button type="submit" class="rounded bg-sky-600 px-2 py-1 text-xs text-white hover:bg-sky-700">
                                                        Kirim
                                                    </button>
                                                    <button type="button" @click="mode=null" class="rounded bg-white px-2 py-1 text-xs text-slate-700 border border-slate-300">
                                                        Batal
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    @endcan

                                    {{-- Kasi --}}
                                    @can('approveKasi', $target)
                                        <div x-show="mode==='kasi'" x-transition class="rounded-lg border border-emerald-100 bg-emerald-50 p-3">
                                            <form method="POST" action="{{ route('resolution-targets.approve-kasi', $target) }}" class="space-y-2">
                                                @csrf
                                                <textarea name="notes" rows="2"
                                                        class="w-full rounded-lg border-slate-300 text-xs"
                                                        placeholder="Catatan Kasi (opsional)"></textarea>
                                                <div class="flex gap-2">
                                                    <button type="submit" class="rounded bg-emerald-600 px-2 py-1 text-xs text-white hover:bg-emerald-700">
                                                        Kirim
                                                    </button>
                                                    <button type="button" @click="mode=null" class="rounded bg-white px-2 py-1 text-xs text-slate-700 border border-slate-300">
                                                        Batal
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    @endcan

                                    {{-- Reject --}}
                                    @can('reject', $target)
                                        <div x-show="mode==='reject'" x-transition class="rounded-lg border border-rose-100 bg-rose-50 p-3">
                                            <form method="POST" action="{{ route('resolution-targets.reject', $target) }}" class="space-y-2">
                                                @csrf
                                                <textarea name="reject_reason" rows="2" required
                                                        class="w-full rounded-lg border-slate-300 text-xs"
                                                        placeholder="Alasan penolakan (wajib)"></textarea>
                                                <div class="flex gap-2">
                                                    <button type="submit" class="rounded bg-rose-600 px-2 py-1 text-xs text-white hover:bg-rose-700">
                                                        Kirim
                                                    </button>
                                                    <button type="button" @click="mode=null" class="rounded bg-white px-2 py-1 text-xs text-slate-700 border border-slate-300">
                                                        Batal
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    @endcan

                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

        @endif
    </div>

</div>
