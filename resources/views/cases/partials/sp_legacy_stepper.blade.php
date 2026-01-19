{{-- resources/views/cases/partials/sp_legacy_stepper.blade.php --}}

@php
    /**
     * Asumsi data:
     * - $case ada (NplCase)
     * - $case->actions berisi CaseAction legacy_sp, action_type = spak/sp1/sp2/sp3/spt/spjad
     * - meta['sp'] menyimpan status, letter_no, issued_at, due_at, delivery_method, receipt_no, final_notes, ship_notes
     */

    $steps = [
        'spak'  => ['key'=>'spak',  'title'=>'SPAK',  'desc'=>'Surat Pemberitahuan Angsuran Kredit'],
        'sp1'   => ['key'=>'sp1',   'title'=>'SP1',   'desc'=>'Surat Peringatan 1'],
        'sp2'   => ['key'=>'sp2',   'title'=>'SP2',   'desc'=>'Surat Peringatan 2'],
        'sp3'   => ['key'=>'sp3',   'title'=>'SP3',   'desc'=>'Surat Peringatan 3'],
        'spt'   => ['key'=>'spt',   'title'=>'SPT',   'desc'=>'Surat Peringatan Terakhir'],
        'spjad' => ['key'=>'spjad', 'title'=>'SPJAD', 'desc'=>'Pemberitahuan jaminan akan dilelang'],
    ];

    $legacyActions = $case->actions
        ->where('source_system', 'legacy_sp')
        ->filter(fn($a) => in_array(strtolower($a->action_type ?? ''), array_keys($steps), true));

    $latestByStep = $legacyActions
        ->groupBy(fn($a) => strtolower($a->action_type ?? ''))
        ->map(function ($group) {
            return $group->sortByDesc(function ($a) {
                // prioritas action_at, fallback created_at
                return optional($a->action_at)->timestamp
                    ?? optional($a->created_at)->timestamp
                    ?? 0;
            })->first();
        });

    $now = now();

    $getMeta = function($action) {
        $m = $action?->meta;
        if (is_string($m)) $m = json_decode($m, true);
        return is_array($m) ? $m : [];
    };

    $getSp = function($action) use ($getMeta) {
        $meta = $getMeta($action);
        $sp   = $meta['sp'] ?? [];
        return is_array($sp) ? $sp : [];
    };

    // hitung state lock: step N terkunci kalau step sebelumnya belum final
    $stepKeys = array_keys($steps);

    $resolveState = function(string $key) use ($latestByStep, $getSp, $now, $stepKeys) {

        $idx = array_search($key, $stepKeys, true);
        $prevKey = $idx !== false && $idx > 0 ? $stepKeys[$idx-1] : null;

        // action terbaru untuk step ini
        $action = $latestByStep->get($key);
        $sp = $getSp($action);

        // ===== status utama: meta.sp.status -> fallback result -> fallback kosong
        $status = strtolower(trim((string)($sp['status'] ?? ($action?->result ?? ''))));

        // normalisasi status “result” legacy yang kadang ISSUED/SENT/RECEIVED dsb
        // (biar konsisten)
        $status = match(true) {
            in_array($status, ['issued','sent','delivered','delivered_tracking','received','returned','unknown','closed'], true) => $status,
            $status === 'done'  => 'received',
            $status === 'final' => 'received',
            default => $status, // biarkan apa adanya kalau aneh, tapi nanti UI tetep aman
        };

        // final statuses
        $isFinal = in_array($status, ['received','returned','unknown','closed'], true);

        // ===== tanggal
        $issuedAt = !empty($sp['issued_at'])
            ? \Carbon\Carbon::parse($sp['issued_at'])
            : ($action?->action_at ? \Carbon\Carbon::parse($action->action_at) : null);

        $dueAt = !empty($sp['due_at'])
            ? \Carbon\Carbon::parse($sp['due_at'])
            : null;

        $hasIssued = $issuedAt !== null;

        // ===== overdue
        $overdue = $hasIssued && $dueAt && $now->greaterThan($dueAt) && !$isFinal;

        // ===== sent?
        $isSent = in_array($status, ['sent','delivered','delivered_tracking'], true);

        // ===== LOCK RULE (legacy-friendly):
        // - kalau step ini sudah ada data legacy => JANGAN lock
        // - kalau step ini belum ada => lock hanya jika prev step juga belum ada
        $existsThis = (bool) $action;
        $existsPrev = $prevKey ? (bool) $latestByStep->get($prevKey) : true;

        $locked = (!$existsThis) && (!$existsPrev);
        $lockReason = $locked && $prevKey ? strtoupper($prevKey)." belum ada (Legacy)." : null;

        // anggap legacy-import kalau action ada dari legacy_sp dan ada minimal issued_at / letter_no / status
        $importedFromLegacy = false;
        if ($action) {
            $importedFromLegacy =
                !empty($sp['issued_at']) ||
                !empty($sp['letter_no']) ||
                !empty($sp['status']);
        }

        // optional: kalau kamu punya field di CaseAction misalnya imported_at / source_ref_id, bisa lebih presisi

        return [
            'action'     => $action,
            'sp'         => $sp,

            'importedFromLegacy' => $importedFromLegacy,

            'locked'     => $locked,
            'lockReason' => $lockReason,
            'hasIssued'  => $hasIssued,
            'isSent'     => $isSent,
            'isFinal'    => $isFinal,
            'overdue'    => $overdue,
            'issuedAt'   => $issuedAt,
            'dueAt'      => $dueAt,
            'status'     => $status,
        ];

    };

    // badge helper
    $badge = function(string $text, string $tone='slate') {
        $map = [
            'slate'  => 'bg-slate-100 text-slate-700 ring-slate-200',
            'blue'   => 'bg-blue-50 text-blue-700 ring-blue-200',
            'indigo' => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
            'amber'  => 'bg-amber-50 text-amber-800 ring-amber-200',
            'rose'   => 'bg-rose-50 text-rose-700 ring-rose-200',
            'emerald'=> 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        ];
        $cls = $map[$tone] ?? $map['slate'];
        return '<span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 '.$cls.'">'.$text.'</span>';
    };

    $fmt = fn($dt) => $dt ? $dt->format('d M Y') : '-';
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h3 class="text-sm font-bold text-slate-900">SP / Surat Peringatan (Legacy)</h3>
            <p class="mt-0.5 text-xs text-slate-500">
                SPAK–SP3–SPT–SPJAD dibuat di aplikasi legacy, tapi progres & follow-up tampil di timeline.
            </p>
        </div>
        <span class="rounded-full bg-slate-100 px-3 py-1 text-[11px] font-semibold text-slate-700">
            Step: {{ count($steps) }}
        </span>
    </div>

    {{-- Stepper --}}
    <div class="mt-4 relative">
        {{-- gradient fade kiri/kanan biar keliatan bisa scroll --}}
        <div class="pointer-events-none absolute inset-y-0 left-0 w-10 bg-gradient-to-r from-white to-transparent rounded-2xl"></div>
        <div class="pointer-events-none absolute inset-y-0 right-0 w-10 bg-gradient-to-l from-white to-transparent rounded-2xl"></div>

        <div class="flex gap-4 overflow-x-auto pb-4 pr-2 snap-x snap-mandatory scroll-smooth sp-stepper">

            @foreach($steps as $key => $cfg)
            
                @php
                    $st = $resolveState($key);
                    
                    // READ ONLY: kalau sudah ada data legacy, user tidak boleh edit
                    $readOnly = (bool) ($st['importedFromLegacy'] ?? false);

                    // sekarang canAct harus mempertimbangkan read-only
                    $canAct = !$st['locked'] && !$readOnly;

                    // kalau readOnly, tombol issue/ship/final jangan muncul aktif                  

                    // header highlight
                    $topRing = $st['locked'] ? 'ring-slate-200' : 'ring-indigo-200';
                    $topBg   = $st['locked'] ? 'bg-slate-50' : 'bg-white';

                    // status label utama
                    $mainLabel = 'BELUM ADA';
                    $mainTone  = 'slate';

                    if ($st['hasIssued']) {
                        $mainLabel = 'ISSUED';
                        $mainTone  = 'indigo';
                    }

                    if ($st['isSent']) {
                        $mainLabel = 'SENT';
                        $mainTone  = 'blue';
                    }

                    if ($st['isFinal']) {
                        $mainLabel = strtoupper($st['status'] ?: 'FINAL');
                        $mainTone  = 'emerald';
                    }

                    // badge kedua: overdue / lock
                    $secondaryBadgeHtml = '';
                    if ($st['overdue']) {
                        $secondaryBadgeHtml = $badge('⏰ OVERDUE', 'amber');
                    } elseif ($st['locked']) {
                        $secondaryBadgeHtml = $badge('🔒 LOCK', 'slate');
                    }

                    // CTA rules
                    // - BELUM ADA => Issue
                    // - ISSUED (belum final) => Catat Kirim
                    // - SENT / ISSUED => Final (selama belum final)
                    $canAct = !$st['locked'];

                    $showIssue = !$st['hasIssued'];
                    $showShip  = $st['hasIssued'] && !$st['isFinal'] && !$st['isSent'];
                    $showFinal = $st['hasIssued'] && !$st['isFinal'];

                    // summary values
                    $letterNo = (string)($st['sp']['letter_no'] ?? '-');
                    $issuedAt = $fmt($st['issuedAt']);
                    $dueAt    = $fmt($st['dueAt']);
                @endphp

                <div class="min-w-[320px] max-w-[320px] snap-start">
                    {{-- card: pakai flex-col supaya tombol selalu di bawah --}}
                    <div class="h-full min-h-[340px] rounded-2xl ring-1 {{ $topRing }} {{ $topBg }} p-4 flex flex-col">
                        {{-- Header --}}
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="text-base font-bold text-slate-900 tracking-tight">{{ $cfg['title'] }}</div>
                                <div class="mt-0.5 text-xs text-slate-500 leading-snug">{{ $cfg['desc'] }}</div>
                            </div>

                            <div class="shrink-0 flex flex-col items-end gap-2">
                                <div class="flex flex-wrap items-center justify-end gap-2">
                                    {!! $badge($mainLabel, $mainTone) !!}
                                    {!! $secondaryBadgeHtml !!}
                                </div>

                                @if($st['locked'])
                                    <div class="text-[10px] text-slate-400 leading-none">Locked</div>
                                @endif
                            </div>
                        </div>

                        {{-- Info --}}
                        <div class="mt-4 rounded-2xl bg-white/80 p-3 ring-1 ring-slate-200">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-[11px] text-slate-500">No Surat</div>
                                    <div class="mt-0.5 text-sm font-semibold text-slate-900 truncate" title="{{ $letterNo }}">
                                        {{ $letterNo }}
                                    </div>
                                </div>

                                @if(!empty($st['sp']['delivery_method']))
                                    <div class="shrink-0 text-[11px] text-slate-500">
                                        <span class="font-semibold text-slate-700">Via:</span>
                                        {{ strtoupper((string)$st['sp']['delivery_method']) }}
                                    </div>
                                @endif
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-3 text-xs">
                                <div class="rounded-xl bg-slate-50 p-2 ring-1 ring-slate-200">
                                    <div class="text-[11px] text-slate-500">Issued</div>
                                    <div class="mt-0.5 font-semibold text-slate-800">{{ $issuedAt }}</div>
                                </div>
                                <div class="rounded-xl bg-slate-50 p-2 ring-1 ring-slate-200">
                                    <div class="text-[11px] text-slate-500">Due</div>
                                    <div class="mt-0.5 font-semibold text-slate-800">{{ $dueAt }}</div>
                                </div>
                            </div>

                            @if($st['locked'])
                                <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-slate-600">
                                    <span class="font-semibold">Terkunci:</span> {{ $st['lockReason'] }}
                                </div>
                            @endif

                            @if(!empty($st['sp']['receipt_no']))
                                <div class="mt-2 text-[11px] text-slate-500">
                                    <span class="font-semibold text-slate-700">Resi:</span> {{ $st['sp']['receipt_no'] }}
                                </div>
                            @endif
                        </div>

                        {{-- Actions --}}
                        {{-- spacer supaya konten atas fleksibel --}}
                        <div class="flex-1"></div>

                        {{-- Actions: nempel bawah --}}
                        {{-- Actions --}}
                        <div class="mt-4">
                            @if($readOnly)
                                <button
                                    type="button"
                                    class="h-10 w-full inline-flex items-center justify-center gap-2 rounded-xl px-3 text-xs font-semibold ring-1 transition
                                        bg-white text-slate-700 ring-slate-200 hover:bg-slate-50"
                                    x-data
                                    @click="$dispatch('sp-open', { mode:'view', key:'{{ $key }}' })"
                                >
                                    <span class="text-sm">👁</span><span>Lihat (Read-only)</span>
                                </button>

                                <div class="mt-3 text-[11px] text-slate-500 leading-snug">
                                    Data step ini berasal dari aplikasi legacy. Perubahan dilakukan di legacy.
                                </div>
                            @else
                                <div class="grid grid-cols-3 gap-2">
                                    {{-- Issue --}}
                                    <button
                                        type="button"
                                        @disabled(!$canAct || !$showIssue)
                                        class="h-10 inline-flex items-center justify-center gap-2 rounded-xl px-3 text-xs font-semibold ring-1 transition
                                        {{ ($canAct && $showIssue) ? 'bg-indigo-600 text-white ring-indigo-600 hover:bg-indigo-700' : 'bg-slate-100 text-slate-400 ring-slate-200 cursor-not-allowed' }}"
                                        title="{{ $st['locked'] ? $st['lockReason'] : '' }}"
                                        x-data
                                        @click="$dispatch('sp-open', { mode:'issue', key:'{{ $key }}' })"
                                    >
                                        <span class="text-sm">➕</span><span>Issue</span>
                                    </button>

                                    {{-- Ship --}}
                                    <button
                                        type="button"
                                        @disabled(!$canAct || !$showShip)
                                        class="h-10 inline-flex items-center justify-center gap-2 rounded-xl px-3 text-xs font-semibold ring-1 transition
                                        {{ ($canAct && $showShip) ? 'bg-white text-indigo-700 ring-indigo-200 hover:bg-indigo-50' : 'bg-slate-100 text-slate-400 ring-slate-200 cursor-not-allowed' }}"
                                        title="{{ $st['locked'] ? $st['lockReason'] : '' }}"
                                        x-data
                                        @click="$dispatch('sp-open', { mode:'ship', key:'{{ $key }}' })"
                                    >
                                        <span class="text-sm">📦</span><span>Kirim</span>
                                    </button>

                                    {{-- Final --}}
                                    <button
                                        type="button"
                                        @disabled(!$canAct || !$showFinal)
                                        class="h-10 inline-flex items-center justify-center gap-2 rounded-xl px-3 text-xs font-semibold ring-1 transition
                                        {{ ($canAct && $showFinal) ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100' : 'bg-slate-100 text-slate-400 ring-slate-200 cursor-not-allowed' }}"
                                        title="{{ $st['locked'] ? $st['lockReason'] : '' }}"
                                        x-data
                                        @click="$dispatch('sp-open', { mode:'final', key:'{{ $key }}' })"
                                    >
                                        <span class="text-sm">✅</span><span>Final</span>
                                    </button>
                                </div>

                                <div class="mt-3 text-[11px] text-slate-500 leading-snug">
                                    @if(!$st['hasIssued'])
                                        Isi nomor surat & tanggal issue.
                                    @elseif($st['hasIssued'] && !$st['isFinal'])
                                        Catat kirim (opsional resi), lalu final (received/returned/unknown/closed).
                                    @else
                                        Step selesai.
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-2 text-[11px] text-slate-400 flex items-center justify-between">
            <span>Scroll ke kanan untuk step berikutnya.</span>
            <span class="hidden sm:inline">Tip: gunakan shift + scroll wheel.</span>
        </div>
    </div>

    {{-- Modal host (1 modal dipakai untuk semua step) --}}
    <div
        x-data="{
            open:false, mode:'', key:'',
            init() {
                window.addEventListener('sp-open', (e) => {
                    this.open = true;
                    this.mode = e.detail.mode;
                    this.key  = e.detail.key;
                });
            }
        }"
    >
        <div x-show="open" x-cloak class="fixed inset-0 z-40 bg-black/40"></div>

        <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="w-full max-w-lg rounded-2xl bg-white shadow-xl ring-1 ring-slate-200">
                <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
                    <div>
                        <div class="text-lg font-bold text-slate-900">
                            <span x-text="
                                    (mode==='issue' ? 'Issue' :
                                    mode==='ship'  ? 'Catat Kirim' :
                                    mode==='final' ? 'Final' :
                                    mode==='view'  ? 'Lihat' : '')
                                "></span>
                            <span class="ml-1 text-slate-500" x-text="key.toUpperCase()"></span>
                        </div>
                        <div class="mt-1 text-xs text-slate-500">
                            Input mengikuti data di aplikasi legacy, tapi tercatat di timeline CRMS.
                        </div>
                    </div>

                    <button type="button" @click="open=false"
                        class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        Tutup
                    </button>
                </div>

                {{-- ISSUE FORM --}}
                <form x-show="mode==='issue'" x-cloak method="POST"
                      :action="`{{ route('cases.sp_legacy.issue', [$case, 'TYPE_PLACEHOLDER']) }}`.replace('TYPE_PLACEHOLDER', key)"
                      class="px-5 py-4 space-y-4">
                    @csrf
                    <div>
                        <label class="text-xs font-semibold text-slate-700">Nomor Surat</label>
                        <input name="letter_no" type="text" required
                               class="mt-1 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="misal: 001/SP1/MSA/IX/2025">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-700">Tanggal Issue</label>
                        <input name="issued_at" type="date" required
                               class="mt-1 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <div class="mt-1 text-[11px] text-slate-500">Due otomatis +7 hari (sesuai kebijakan kemarin).</div>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-700">Catatan (opsional)</label>
                        <textarea name="notes" rows="2"
                                  class="mt-1 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                  placeholder="misal: dicetak & ditandatangani, siap kirim"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" @click="open=false"
                            class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Batal
                        </button>
                        <button type="submit"
                            class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">
                            Simpan Issue
                        </button>
                    </div>
                </form>

                {{-- SHIP FORM --}}
                <form x-show="mode==='ship'" x-cloak method="POST"
                      :action="`{{ route('cases.sp_legacy.ship', [$case, 'TYPE_PLACEHOLDER']) }}`.replace('TYPE_PLACEHOLDER', key)"
                      class="px-5 py-4 space-y-4">
                    @csrf

                    <div>
                        <label class="text-xs font-semibold text-slate-700">Metode Pengiriman</label>
                        <select name="delivery_method" required
                                class="mt-1 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">-- pilih --</option>
                            <option value="pos">POS / Ekspedisi</option>
                            <option value="kurir">Kurir</option>
                            <option value="petugas_bank">Petugas Bank</option>
                            <option value="kuasa_hukum">Kuasa Hukum</option>
                            <option value="lainnya">Lainnya</option>
                        </select>
                        <div class="mt-1 text-[11px] text-slate-500">Resi opsional (kalau ada).</div>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-700">No Resi (opsional)</label>
                        <input name="receipt_no" type="text"
                               class="mt-1 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                               placeholder="misal: JNE123...">
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-700">Catatan (opsional)</label>
                        <textarea name="notes" rows="2"
                                  class="mt-1 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                  placeholder="misal: dikirim via JNE, resi terlampir"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" @click="open=false"
                            class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Batal
                        </button>
                        <button type="submit"
                            class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">
                            Simpan Kirim
                        </button>
                    </div>
                </form>

                {{-- FINAL FORM --}}
                <form x-show="mode==='final'" x-cloak method="POST"
                      :action="`{{ route('cases.sp_legacy.finalize', [$case, 'TYPE_PLACEHOLDER']) }}`.replace('TYPE_PLACEHOLDER', key)"
                      class="px-5 py-4 space-y-4">
                    @csrf

                    <div>
                        <label class="text-xs font-semibold text-slate-700">Status Akhir</label>
                        <select name="final_status" required
                                class="mt-1 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">-- pilih --</option>
                            <option value="received">Diterima Debitur</option>
                            <option value="returned">Dikembalikan (Return)</option>
                            <option value="unknown">Tidak diketahui</option>
                            <option value="closed">Ditutup</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-700">Catatan (opsional)</label>
                        <textarea name="final_notes" rows="2"
                                  class="mt-1 w-full rounded-xl border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                  placeholder="misal: diterima oleh keluarga debitur, ttd di POD"></textarea>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                        <button type="button" @click="open=false"
                            class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                            Batal
                        </button>
                        <button type="submit"
                            class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">
                            Simpan Final
                        </button>
                    </div>
                </form>
                {{-- VIEW (READ-ONLY) --}}
                <div x-show="mode==='view'" x-cloak class="px-5 py-4 space-y-3">
                    @php
                        // server-side data untuk semua step, supaya view modal bisa baca
                        $spViewData = [];
                        foreach($steps as $__k => $__cfg){
                            $__st = $resolveState($__k);
                            $spViewData[$__k] = [
                                'title' => $__cfg['title'],
                                'desc'  => $__cfg['desc'],
                                'status'=> $__st['status'] ?? '',
                                'letter_no' => $__st['sp']['letter_no'] ?? null,
                                'issued_at' => $__st['sp']['issued_at'] ?? null,
                                'due_at'    => $__st['sp']['due_at'] ?? null,
                                'delivery_method' => $__st['sp']['delivery_method'] ?? null,
                                'receipt_no' => $__st['sp']['receipt_no'] ?? null,
                                'notes'      => $__st['sp']['notes'] ?? null,
                                'ship_notes' => $__st['sp']['ship_notes'] ?? null,
                                'final_notes'=> $__st['sp']['final_notes'] ?? null,
                            ];
                        }
                    @endphp

                    <template x-if="key && {{ \Illuminate\Support\Js::from($spViewData) }}[key]">
                        <div class="space-y-3">
                            <div class="rounded-2xl bg-slate-50 p-4 ring-1 ring-slate-200">
                                <div class="text-sm font-bold text-slate-900" x-text="{{ \Illuminate\Support\Js::from($spViewData) }}[key].title"></div>
                                <div class="mt-0.5 text-xs text-slate-500" x-text="{{ \Illuminate\Support\Js::from($spViewData) }}[key].desc"></div>

                                <div class="mt-4 grid grid-cols-2 gap-3 text-xs">
                                    <div>
                                        <div class="text-[11px] text-slate-500">Status</div>
                                        <div class="mt-0.5 font-semibold text-slate-800" x-text="{{ \Illuminate\Support\Js::from($spViewData) }}[key].status || '-'"></div>
                                    </div>
                                    <div>
                                        <div class="text-[11px] text-slate-500">No Surat</div>
                                        <div class="mt-0.5 font-semibold text-slate-800" x-text="{{ \Illuminate\Support\Js::from($spViewData) }}[key].letter_no || '-'"></div>
                                    </div>

                                    <div>
                                        <div class="text-[11px] text-slate-500">Issued</div>
                                        <div class="mt-0.5 font-semibold text-slate-800" x-text="{{ \Illuminate\Support\Js::from($spViewData) }}[key].issued_at || '-'"></div>
                                    </div>
                                    <div>
                                        <div class="text-[11px] text-slate-500">Due</div>
                                        <div class="mt-0.5 font-semibold text-slate-800" x-text="{{ \Illuminate\Support\Js::from($spViewData) }}[key].due_at || '-'"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="rounded-2xl bg-white p-4 ring-1 ring-slate-200 text-xs space-y-2">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-slate-500">Metode Kirim</span>
                                    <span class="font-semibold text-slate-800" x-text="{{ \Illuminate\Support\Js::from($spViewData) }}[key].delivery_method || '-'"></span>
                                </div>
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-slate-500">No Resi</span>
                                    <span class="font-semibold text-slate-800" x-text="{{ \Illuminate\Support\Js::from($spViewData) }}[key].receipt_no || '-'"></span>
                                </div>

                                <div class="pt-2 border-t border-slate-200">
                                    <div class="text-slate-500">Catatan Issue</div>
                                    <div class="mt-1 text-slate-800" x-text="{{ \Illuminate\Support\Js::from($spViewData) }}[key].notes || '-'"></div>
                                </div>

                                <div class="pt-2 border-t border-slate-200">
                                    <div class="text-slate-500">Catatan Kirim</div>
                                    <div class="mt-1 text-slate-800" x-text="{{ \Illuminate\Support\Js::from($spViewData) }}[key].ship_notes || '-'"></div>
                                </div>

                                <div class="pt-2 border-t border-slate-200">
                                    <div class="text-slate-500">Catatan Final</div>
                                    <div class="mt-1 text-slate-800" x-text="{{ \Illuminate\Support\Js::from($spViewData) }}[key].final_notes || '-'"></div>
                                </div>
                            </div>

                            <div class="flex justify-end gap-2 border-t border-slate-200 pt-4">
                                <button type="button" @click="open=false"
                                    class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                    Tutup
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

            </div>
        </div>
    </div>
</div>
