@extends('layouts.app')

@section('title', 'Detail Kasus Kredit Bermasalah')

@section('content')
@php
    $loan = $case->loanAccount;

    // akses mulai legal
    $canStart = auth()->user()?->can('startLegalAction', $case);
@endphp

<div class="mx-auto w-full max-w-6xl space-y-5 px-4 py-6">

    {{-- Breadcrumb --}}
    <div class="text-xs text-slate-500">
        <a href="{{ route('cases.index') }}" class="hover:underline">Daftar Kasus</a>
        <span class="mx-1">/</span>
        <span>Detail</span>
    </div>

    {{-- Header (ringkas, tanpa tombol eskalasi) --}}
    <div class="rounded-2xl border border-slate-100 bg-white px-4 py-3 shadow-sm">
        <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
            <div>
                <p class="mb-1 text-xs text-slate-500">Rekening</p>
                <h1 class="text-xl font-semibold text-slate-800 md:text-2xl">
                    {{ $loan?->account_no ?? '-' }} — {{ $loan?->customer_name ?? '-' }}
                </h1>
                <p class="mt-1 text-xs text-slate-500">
                    CIF: <span class="font-mono">{{ $loan?->cif ?? '-' }}</span> •
                    Collector: {{ $loan?->ao_name ?? '-' }} ({{ $loan?->ao_code ?? '-' }})
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    Alamat: <span class="font-mono">{{ $loan?->alamat ?? '-' }}</span>
                </p>
            </div>

            <div class="flex flex-col gap-2 text-xs md:items-end">
                <div>
                    <span class="text-slate-500">Kolektibilitas:</span>
                    <span class="font-semibold text-slate-800">{{ $loan?->kolek ?? '-' }}</span>
                </div>
                <div>
                    <span class="text-slate-500">DPD:</span>
                    <span class="font-semibold text-slate-800">{{ $loan?->dpd ?? '-' }} hari</span>
                </div>
                <div>
                    <span class="text-slate-500">Outstanding:</span>
                    <span class="font-semibold text-slate-800">
                        {{ $loan ? 'Rp '.number_format($loan->outstanding, 0, ',', '.') : '-' }}
                    </span>
                </div>
                <div>
                    <span class="text-slate-500">Opened:</span>
                    <span class="font-semibold text-slate-800">
                        {{ $case->opened_at?->format('d-m-Y') ?? '-' }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-slate-500">Status Case:</span>
                    @if ($case->closed_at)
                        <span class="inline-flex rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-semibold text-slate-700">
                            Closed {{ $case->closed_at?->format('d-m-Y') }}
                        </span>
                    @else
                        <span class="inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-700">
                            Open
                        </span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- GRID FINAL --}}
    <div class="grid gap-5 lg:grid-cols-12">

        {{-- KIRI: fokus kerja harian --}}
        <div class="space-y-5 lg:col-span-8">

            {{-- Form tambah action --}}
            <div class="rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm">
                <div class="mb-3">
                    <h2 class="text-base font-bold text-slate-900">🛠 Penanganan Kasus</h2>
                    <p class="mt-0.5 text-sm text-slate-500">
                        Catat setiap upaya penagihan / komunikasi. Ini yang jadi “bukti proses” sebelum eskalasi.
                    </p>
                </div>

                @if (session('status'))
                    <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-xs text-emerald-700">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-3 rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-xs text-red-700">
                        <ul class="list-disc list-inside">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('cases.actions.store', $case) }}" class="grid grid-cols-1 gap-3 text-xs md:grid-cols-3">
                    @csrf

                    <div>
                        <label class="mb-1 block text-slate-600">Tanggal & Jam Tindakan</label>
                        <input type="datetime-local" name="action_at"
                               value="{{ old('action_at') }}"
                               class="w-full rounded-lg border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue">
                        <p class="mt-1 text-[10px] text-slate-400">Kosongkan jika sekarang.</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-slate-600">Jenis Tindakan</label>

                        <select name="action_type"
                                onchange="if(this.value==='visit'){ window.location='{{ route('cases.visits.quickStart', $case) }}'; }"
                                class="w-full rounded-lg border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue">
                            <option value="">-- pilih --</option>
                            <option value="whatsapp" @selected(old('action_type')=='whatsapp')>WhatsApp</option>
                            <option value="telpon"   @selected(old('action_type')=='telpon')>Telepon</option>
                            <option value="visit">Kunjungan Lapangan</option>
                            <option value="negosiasi" @selected(old('action_type')=='negosiasi')>Negosiasi</option>
                            <option value="lainnya"   @selected(old('action_type')=='lainnya')>Lainnya</option>
                        </select>

                        <p class="mt-1 text-[10px] text-slate-400">
                            * SP1–SP3 / SPT / SPJAD dikelola lewat menu <b>SP (Legacy)</b>.
                        </p>
                    </div>

                    <div>
                        <label class="mb-1 block text-slate-600">Hasil Singkat</label>
                        <input type="text" name="result"
                               value="{{ old('result') }}"
                               class="w-full rounded-lg border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue"
                               placeholder="misal: janji bayar tgl 15, tidak ketemu, dsb">
                    </div>

                    <div class="md:col-span-3">
                        <label class="mb-1 block text-slate-600">Detail Tindakan / Catatan</label>
                        <textarea name="description" rows="2"
                                  class="w-full rounded-lg border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue"
                                  placeholder="Contoh: kunjungan ke alamat rumah, bertemu istri debitur, disampaikan kewajiban pembayaran...">{{ old('description') }}</textarea>
                    </div>

                    <div>
                        <label class="mb-1 block text-slate-600">Rencana Tindak Lanjut</label>
                        <input type="text" name="next_action"
                               value="{{ old('next_action') }}"
                               class="w-full rounded-lg border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue"
                               placeholder="misal: kirim SP1, kunjungan ulang, ajukan restruktur">
                    </div>

                    <div>
                        <label class="mb-1 block text-slate-600">Target Tanggal Tindak Lanjut</label>
                        <input type="date" name="next_action_due"
                               value="{{ old('next_action_due') }}"
                               class="w-full rounded-lg border-slate-300 px-2 py-1.5 focus:border-msa-blue focus:ring-msa-blue">
                    </div>

                    <div class="flex justify-end md:col-span-3">
                        <button type="submit"
                                class="inline-flex items-center rounded-lg bg-msa-blue px-4 py-2 text-xs font-semibold text-white hover:bg-blue-900">
                            Simpan Log Progres
                        </button>
                    </div>
                </form>
            </div>

            @php
                $steps = ['spak','sp1','sp2','sp3','spt','spjad'];

                // Ambil semua action legacy yang relevan
                $legacyActions = $case->actions
                    ->where('source_system', 'legacy_sp')
                    ->filter(fn($a) => in_array(strtolower($a->action_type ?? ''), $steps, true));

                // Group per step, ambil yang TERBARU (prioritas action_at, fallback created_at)
                $latestByStep = $legacyActions
                    ->groupBy(fn($a) => strtolower($a->action_type))
                    ->map(function($group){
                        return $group->sortByDesc(function($a){
                            return optional($a->action_at)->timestamp
                                ?? optional($a->created_at)->timestamp
                                ?? 0;
                        })->first();
                    });
            @endphp

            {{-- SP Legacy Stepper --}}
            @include('cases.partials.sp_legacy_stepper', ['case' => $case])

            {{-- Timeline Actions --}}
            <div class="rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm">
                <div class="mb-3">
                    <h2 class="text-base font-bold text-slate-900">📚 Timeline Penanganan</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Riwayat tindakan yang pernah dicatat.</p>
                </div>

                @if ($case->actions->count() === 0)
                    <p class="text-xs text-slate-500">
                        Belum ada log progres. Silakan tambahkan tindakan pertama di form di atas.
                    </p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="border-b border-slate-100 bg-slate-50">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Waktu</th>
                                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Jenis</th>
                                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Deskripsi</th>
                                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Hasil</th>
                                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Next Action</th>
                                    <th class="px-3 py-2 text-left font-semibold text-slate-600">Aksi</th>
                                </tr>
                            </thead>

                            <tbody>
                                @foreach ($case->actions as $action)
                                    @php
                                        // 1) Normalize meta (array|null)
                                        $meta = $action->meta;
                                        if (is_string($meta)) $meta = json_decode($meta, true);
                                        if (!is_array($meta)) $meta = [];

                                        // 2) Detect special sources
                                        $sourceSystem = (string)($action->source_system ?? '');
                                        $actionType   = strtoupper((string)($action->action_type ?? '-'));
                                        $resultText   = (string)($action->result ?? '');

                                        // Legacy proof
                                        $isLegacy = ($sourceSystem === 'legacy_sp') && !empty($action->source_ref_id);
                                        $hasProof = (bool)($meta['has_proof'] ?? false);
                                        if (!$hasProof && $resultText === 'BUKTI:ADA') $hasProof = true;

                                        // Non-Litigasi (submit/approve/reject)
                                        $isNonLit = \Illuminate\Support\Str::startsWith($sourceSystem, 'non_litigation');
                                        $nonLitId = $action->source_ref_id ?: ($meta['non_litigation_action_id'] ?? null);
                                        $nonLitUrl = $nonLitId ? route('nonlit.show', $nonLitId) : null;

                                        // Legal (create/status/etc)
                                        $isLegal = \Illuminate\Support\Str::startsWith($sourceSystem, 'legal_')
                                            || $actionType === 'LEGAL';

                                        $legalId = $action->source_ref_id ?: ($meta['legal_action_id'] ?? null);

                                        $legalUrl = null;
                                        if ($legalId) {
                                            $legal = \App\Models\LegalAction::select('id','action_type')->find($legalId);

                                            if ($legal && ($legal->action_type ?? '') === \App\Models\LegalAction::TYPE_HT_EXECUTION) {
                                                $legalUrl = route('legal-actions.ht.show', [
                                                    'action' => $legal->id,
                                                    'tab' => 'summary',
                                                ]);
                                            } else {
                                                $legalUrl = route('legal-actions.show', $legalId);
                                            }
                                        }
                                       
                                        // 3) Badge styles per type
                                        $badgeClass = match (true) {
                                            $isLegal => 'bg-rose-50 text-rose-700 ring-rose-200',
                                            $isNonLit => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
                                            $actionType === 'VISIT' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                            in_array($actionType, ['SP1','SP2','SP3','SPT','SPJAD'], true) => 'bg-amber-50 text-amber-700 ring-amber-200',
                                            default => 'bg-slate-100 text-slate-700 ring-slate-200',
                                        };

                                        // Result chip style
                                        $resultUpper = strtoupper(trim($resultText));
                                        $resultClass = match (true) {
                                            str_contains($resultUpper, 'APPROV') => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                            str_contains($resultUpper, 'REJECT') => 'bg-rose-50 text-rose-700 ring-rose-200',
                                            str_contains($resultUpper, 'DRAFT')  => 'bg-slate-100 text-slate-700 ring-slate-200',
                                            str_contains($resultUpper, 'SUBMIT') => 'bg-blue-50 text-blue-700 ring-blue-200',
                                            str_contains($resultUpper, 'DONE')   => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                            default => 'bg-slate-100 text-slate-700 ring-slate-200',
                                        };

                                        $timeText = $action->action_at ? $action->action_at->format('d-m-Y H:i') : '-';

                                        $desc = (string)($action->description ?? '');
                                        $descLines = preg_split("/\r\n|\n|\r/", $desc);
                                    @endphp

                                    <tr class="align-top border-b border-slate-100 last:border-b-0">
                                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">
                                            {{ $timeText }}
                                        </td>

                                        <td class="px-3 py-2 text-slate-700">
                                            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $badgeClass }}">
                                                {{ $actionType }}
                                            </span>

                                            @if($isLegacy)
                                                <div class="mt-1 text-[10px] text-slate-400">Imported from Legacy SP</div>
                                            @endif

                                            @if($isLegal && !empty($meta['legal_type']))
                                                <div class="mt-1 text-[10px] text-slate-400">
                                                    {{ strtoupper((string)$meta['legal_type']) }}
                                                </div>
                                            @endif
                                        </td>

                                        <td class="px-3 py-2 text-slate-700">
                                            @if (!empty($descLines))
                                                <div class="space-y-1">
                                                    @foreach($descLines as $line)
                                                        @if(trim($line) !== '')
                                                            <div class="leading-relaxed">{{ $line }}</div>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            @else
                                                <span class="text-slate-400">-</span>
                                            @endif
                                        </td>

                                        <td class="px-3 py-2 text-slate-700">
                                            @if(trim($resultText) !== '')
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $resultClass }}">
                                                    {{ $resultUpper }}
                                                </span>
                                            @else
                                                <span class="text-[11px] text-slate-300">-</span>
                                            @endif
                                        </td>

                                        <td class="px-3 py-2 text-slate-700">
                                            @if ($action->next_action)
                                                <div class="leading-relaxed">{{ $action->next_action }}</div>
                                            @else
                                                <div class="text-slate-300">-</div>
                                            @endif

                                            @if ($action->next_action_due)
                                                <div class="mt-1 text-[10px] text-slate-400">
                                                    Target: {{ $action->next_action_due->format('d-m-Y') }}
                                                </div>
                                            @endif
                                        </td>

                                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">
                                            <div class="flex items-center gap-2">

                                                @if($isLegal && $legalUrl)
                                                    @php
                                                        $isHt = ($legalType ?? null) === \App\Models\LegalAction::TYPE_HT_EXECUTION;
                                                    @endphp
                                                    <a href="{{ $legalUrl }}"
                                                    class="inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-[11px] font-semibold
                                                            {{ $isHt ? 'border-indigo-200 text-indigo-700 hover:bg-indigo-50' : 'border-rose-200 text-rose-700 hover:bg-rose-50' }}">
                                                        <span>{{ $isHt ? '🧾' : '⚖️' }}</span>
                                                        <span>{{ $isHt ? 'Buka Form HT' : 'Detail Legal' }}</span>
                                                    </a>
                                                
                                                @elseif($isNonLit && $nonLitUrl)
                                                    <a href="{{ $nonLitUrl }}"
                                                       class="inline-flex items-center gap-1 rounded-lg border border-indigo-200 px-2 py-1 text-indigo-700 hover:bg-indigo-50"
                                                       title="Buka detail usulan Non-Litigasi">
                                                        <span>📄</span>
                                                        <span class="text-[11px] font-semibold">Detail Usulan</span>
                                                    </a>

                                                @elseif($isLegacy && $hasProof)
                                                    <a href="{{ route('cases.actions.legacy_proof', [$case->id, $action->id]) }}"
                                                       target="_blank"
                                                       class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-slate-700 hover:bg-slate-50"
                                                       title="Lihat bukti tanda terima (Legacy)">
                                                        <span>👁</span>
                                                        <span class="text-[11px] font-semibold">Bukti</span>
                                                    </a>
                                                @elseif($isLegacy)
                                                    <span class="inline-flex items-center rounded-lg border border-slate-100 bg-slate-50 px-2 py-1 text-slate-400"
                                                          title="Belum ada bukti di Legacy">
                                                        <span class="text-[11px] font-semibold">Bukti: Belum</span>
                                                    </span>
                                                @else
                                                    <span class="text-[11px] text-slate-300">-</span>
                                                @endif

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

        {{-- KANAN: ringkasan & eskalasi --}}
        <div class="space-y-5 lg:col-span-4">

            {{-- Ringkasan Kredit --}}
            <div class="rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm">
                <p class="mb-2 text-xs font-semibold uppercase text-slate-500">Informasi Kredit</p>
                <dl class="space-y-1 text-xs text-slate-700">
                    <div>
                        <dt class="inline text-slate-500">Produk:</dt>
                        <dd class="inline font-semibold">{{ $loan?->product_type ?? '-' }}</dd>
                    </div>
                    <div>
                        <dt class="inline text-slate-500">Plafond:</dt>
                        <dd class="inline font-semibold">
                            {{ $loan ? 'Rp '.number_format($loan->plafond, 0, ',', '.') : '-' }}
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- Status Kasus --}}
            <div class="rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm">
                <p class="mb-2 text-xs font-semibold uppercase text-slate-500">Status Kasus</p>
                <dl class="space-y-2 text-xs text-slate-700">
                    <div>
                        <dt class="inline text-slate-500">Prioritas:</dt>
                        <dd class="inline font-semibold">{{ strtoupper($case->priority ?? 'normal') }}</dd>
                    </div>
                    <div>
                        <dt class="block text-slate-500">Ringkasan:</dt>
                        <dd class="mt-1 text-slate-700">{{ $case->summary ?? '-' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Catatan --}}
            <div class="rounded-2xl border border-msa-light bg-msa-light/70 px-5 py-4">
                <p class="mb-2 text-xs font-semibold uppercase text-slate-600">Catatan</p>
                <p class="text-xs text-slate-700">
                    Di bawah ini adalah timeline penanganan (telpon, kunjungan, SP, negosiasi, dsb) yang dicatat oleh tim Remedial/Legal.
                    Tambahkan log setiap selesai melakukan tindakan agar progres mudah dimonitor.
                </p>
            </div>

            {{-- Eskalasi (Non-Litigasi + Legal) --}}
            <div x-data="{ open:false, legalOpen:false, type:'' }"
                 class="rounded-2xl border border-amber-200 bg-amber-50/40 p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-extrabold text-amber-900">⚠️ Eskalasi Penanganan</div>
                        <div class="mt-0.5 text-xs text-amber-800">
                            Gunakan bila penanganan biasa belum berhasil (sudah ada catatan di timeline).
                        </div>
                    </div>

                    <button type="button"
                            @click="open = !open"
                            class="rounded-xl border border-amber-200 bg-white px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-50">
                        <span x-text="open ? 'Tutup' : 'Buka'"></span>
                    </button>
                </div>

                <div x-show="open" x-cloak x-transition class="mt-4 space-y-3">

                    {{-- Non-Litigasi --}}
                    <a href="{{ route('cases.nonlit.index', $case) }}"
                       class="flex items-center justify-between rounded-2xl border border-amber-200 bg-white px-4 py-3 text-sm font-semibold text-amber-900 hover:bg-amber-50">
                        <span class="flex items-center gap-2">
                            <span>🧩</span>
                            <span>Non-Litigasi</span>
                        </span>
                        <span class="text-xs text-amber-800">→</span>
                    </a>

                    {{-- Legal --}}
                    <button type="button"
                            @click="legalOpen=true"
                            @disabled(!$canStart)
                            class="flex w-full items-center justify-between rounded-2xl px-4 py-3 text-sm font-semibold text-white
                                {{ $canStart ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-slate-300 cursor-not-allowed' }}"
                            title="{{ $canStart ? '' : 'Anda tidak punya akses untuk memulai tindakan legal' }}">
                        <span class="flex items-center gap-2">
                            <span>⚖️</span>
                            <span>Mulai Tindakan Legal</span>
                        </span>
                        <span class="text-xs text-white/90">+</span>
                    </button>

                    <div class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900/80">
                        Tip: pastikan minimal ada log penanganan yang jelas (telpon/kunjungan/SP) sebelum eskalasi.
                    </div>
                </div>

                {{-- MODAL LEGAL (dipindah ke sidebar, tetap sama logic) --}}
                <div x-show="legalOpen" x-cloak class="fixed inset-0 z-40 bg-black/40"></div>

                <div x-show="legalOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl">
                        <div class="border-b border-slate-200 px-5 py-4">
                            <h3 class="text-lg font-bold text-slate-900">Pilih Jenis Tindakan Legal</h3>
                            <p class="text-sm text-slate-500">Sistem akan membuat Legal Action baru (status: DRAFT).</p>
                        </div>

                        <form method="POST" action="{{ route('npl.legal-actions.store', $case) }}" class="px-5 py-4">
                            @csrf

                            <label class="block text-sm font-semibold text-slate-700">Jenis Tindakan</label>
                            <select x-model="type" name="action_type"
                                    class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">-- Pilih --</option>
                                <option value="somasi">Somasi</option>
                                <option value="ht_execution">Eksekusi HT</option>
                                <option value="fidusia_execution">Eksekusi Fidusia</option>
                                <option value="civil_lawsuit">Gugatan Perdata</option>
                                <option value="pkpu_bankruptcy">PKPU / Kepailitan</option>
                                <option value="criminal_report">Laporan Pidana</option>
                            </select>

                            @error('action_type')
                                <p class="mt-2 text-sm font-semibold text-rose-600">{{ $message }}</p>
                            @enderror

                            <div class="mt-6 flex items-center justify-end gap-3 border-t border-slate-200 pt-4">
                                <button type="button" @click="legalOpen=false"
                                        class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                    Batal
                                </button>

                                <button type="submit" :disabled="!type"
                                        class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">
                                    Buat Draft
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>

        </div>
    </div>

</div>
@endsection
