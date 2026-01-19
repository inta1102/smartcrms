@php
    $exec = $action->htExecution;
    $method = $exec?->method;

    $events = $action->htEvents->sortByDesc('event_at');
    $auctions = $action->htAuctions->sortBy('attempt_no');
    $sale = $action->htUnderhandSale;

    $methodLabel = function($m){
        return match($m){
            'parate' => 'Parate Eksekusi (Lelang KPKNL)',
            'pn' => 'Eksekusi via PN (Fiat + Lelang)',
            'bawah_tangan' => 'Penjualan di Bawah Tangan',
            default => '-',
        };
    };

    $resultBadge = function($r){
        return match($r){
            'laku' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'tidak_laku' => 'bg-amber-50 text-amber-700 border-amber-200',
            'batal' => 'bg-rose-50 text-rose-700 border-rose-200',
            'tunda' => 'bg-slate-100 text-slate-700 border-slate-200',
            default => 'bg-slate-100 text-slate-700 border-slate-200',
        };
    };
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="text-sm font-semibold text-slate-900">Proses & Timeline</div>
            <div class="mt-1 text-sm text-slate-600">
                Metode: <span class="font-semibold">{{ $methodLabel($method) }}</span>
            </div>
        </div>
    </div>

    {{-- Add event --}}
    <div class="mt-5 rounded-xl border border-slate-200 p-4">
        <div class="text-sm font-semibold text-slate-900">Tambah Event Timeline</div>

        <form action="{{ route('legal-actions.ht.events.store', $action) }}" method="POST" class="mt-3 grid grid-cols-1 md:grid-cols-6 gap-3">
            @csrf
            <div class="md:col-span-2">
                <label class="text-xs text-slate-500">Event Type</label>

                <select name="event_type"
                        class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="">- pilih -</option>
                    @foreach(($eventTypes ?? config('ht_events.types')) as $k => $lbl)
                        <option value="{{ $k }}" @selected(old('event_type')===$k)>{{ $lbl }}</option>
                    @endforeach
                    <option value="custom">Lainnya (custom)</option>
                </select>

                <div class="mt-2">
                    <label class="text-xs text-slate-500">Custom label (jika pilih custom)</label>
                    <input type="text" name="event_label"
                        value="{{ old('event_label') }}"
                        class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                        placeholder="mis: Berkas dikembalikan untuk perbaikan lampiran">
                </div>

            </div>
            <div>
                <label class="text-xs text-slate-500">Tanggal</label>
               <input
                    type="text"
                    name="event_date"
                    class="js-datetime mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                    placeholder="Tanggal & Jam"
                    autocomplete="off"
                    value="{{ old('event_date') }}"
                >

            </div>

            <div>
                <label class="text-xs text-slate-500">Ref No</label>
                <input type="text" name="ref_no"
                    value="{{ old('ref_no') }}"
                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
            </div>

            <div class="md:col-span-2">
                <label class="text-xs text-slate-500">Notes</label>
                <input type="text" name="notes"
                    value="{{ old('notes') }}"
                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                    placeholder="catatan singkat">
            </div>

            <div class="md:col-span-6 flex justify-end">
                <button type="submit"
                        class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    ➕ Tambah Event
                </button>
            </div>
        </form>
    </div>

    {{-- Auctions / Underhand --}}
    <div class="mt-5 grid grid-cols-1 lg:grid-cols-2 gap-4">
        {{-- LEFT: Lelang Attempts (parate/pn) --}}
        <div class="rounded-xl border border-slate-200 p-4">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-sm font-semibold text-slate-900">Proses Lelang</div>
                    <div class="mt-1 text-xs text-slate-500">Hanya untuk metode Parate / PN</div>
                </div>
            </div>

            @php
                // UI Guard: Add Attempt hanya boleh saat status SCHEDULED & bukan bawah_tangan & belum final
                $statusLower = strtolower((string) ($status ?? 'draft'));

                $isFinalStatus = in_array($statusLower, ['executed','closed','cancelled','settled'], true);

                $canAddAttempt = !$isFinalStatus
                    && $statusLower === \App\Models\LegalAction::STATUS_SCHEDULED
                    && $method !== 'bawah_tangan';

                $attemptReason = null;

                if ($method === 'bawah_tangan') {
                    $attemptReason = 'Metode bawah tangan tidak menggunakan lelang.';
                } elseif ($isFinalStatus) {
                    $attemptReason = "Attempt lelang tidak bisa ditambah/diubah karena status sudah " . strtoupper($statusLower) . ".";
                } elseif ($statusLower !== \App\Models\LegalAction::STATUS_SCHEDULED) {
                    $attemptReason = "Attempt lelang hanya bisa ditambahkan setelah status menjadi SCHEDULED (jadwal lelang sudah keluar).";
                }
            @endphp

            @if($method === 'bawah_tangan')
                <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                    Metode bawah tangan tidak menggunakan lelang.
                </div>
            @else
                @if($isFinalStatus)
                    <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        🔒 Percobaan lelang tidak bisa ditambah/diubah karena status sudah {{ strtoupper($statusLower) }}.
                    </div>
                @else
                    @if(!$canAddAttempt)
                        <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                            <div class="font-semibold text-slate-800">Form Attempt belum aktif</div>
                            <div class="mt-1 text-xs text-slate-600">
                                {{ $attemptReason }}
                            </div>
                            <div class="mt-2 text-xs text-slate-500">
                                Urutan singkat:
                                <span class="font-semibold">Submit (SUBMITTED)</span> → isi Timeline
                                <span class="font-semibold">Penetapan Jadwal Lelang</span> → lalu
                                <span class="font-semibold">Mark Scheduled</span>.
                            </div>
                        </div>
                    @endif

                    {{-- form attempt --}} 
                    <form action="{{ route('legal-actions.ht.auctions.store', $action) }}"
                        method="POST"
                        enctype="multipart/form-data"
                        x-data="{ result: '{{ old('auction_result','') }}' }"
                        class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3 {{ $canAddAttempt ? '' : 'opacity-50 pointer-events-none' }}">
                        @csrf

                        <div>
                            <label class="text-xs text-slate-500">KPKNL</label>
                            <input type="text" name="kpknl_office"
                                value="{{ old('kpknl_office') }}"
                                class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        </div>

                        <div>
                            <label class="text-xs text-slate-500">No Registrasi</label>
                            <input type="text" name="registration_no"
                                value="{{ old('registration_no') }}"
                                class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        </div>

                        {{-- Rupiah input (display) + hidden raw --}}
                        <div>
                            <label class="text-xs text-slate-500">Limit Value</label>
                            <input type="text"
                                class="js-rupiah mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                value="{{ old('limit_value') }}"
                                placeholder="Rp 0,-"
                                autocomplete="off">
                            <input type="hidden" name="limit_value" value="{{ old('limit_value') }}">
                        </div>

                        <div>
                            <label class="text-xs text-slate-500">Tgl Lelang</label>
                            <input type="date" name="auction_date"
                                value="{{ old('auction_date') }}"
                                class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        </div>

                        <div>
                            <label class="text-xs text-slate-500">Hasil</label>
                            <select name="auction_result"
                                    class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                    x-model="result">
                                <option value="">-</option>
                                <option value="laku">LAKU</option>
                                <option value="tidak_laku">TIDAK LAKU</option>
                                <option value="batal">BATAL</option>
                                <option value="tunda">TUNDA</option>
                            </select>
                        </div>

                        {{-- Slot kosong biar grid rapi --}}
                        <div class="hidden md:block"></div>

                        {{-- MUNCUL JIKA LAKU --}}
                        <template x-if="result === 'laku'">
                            <div class="md:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs text-slate-500">Nilai Laku</label>
                                    <input type="text"
                                        class="js-rupiah mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                        value="{{ old('sold_value') }}"
                                        placeholder="Rp 0,-"
                                        autocomplete="off">
                                    <input type="hidden" name="sold_value" value="{{ old('sold_value') }}">
                                </div>

                                <div>
                                    <label class="text-xs text-slate-500">Pemenang (Nama)</label>
                                    <input type="text" name="winner_name"
                                        value="{{ old('winner_name') }}"
                                        class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
                                        placeholder="Nama pemenang lelang">
                                </div>

                                <div>
                                    <label class="text-xs text-slate-500">Tanggal Pelunasan (Settlement)</label>
                                    <input type="date" name="settlement_date"
                                        value="{{ old('settlement_date') }}"
                                        class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                </div>

                                <div x-data="{ fileName: '' }">
                                    <label class="text-xs text-slate-500">Risalah Lelang (file)</label>
                                    <div class="mt-1 flex items-center gap-2">
                                        <input type="file" name="risalah_file" class="hidden"
                                            x-ref="file"
                                            @change="fileName = $refs.file.files?.[0]?.name || ''">

                                        <button type="button"
                                                class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                                                @click="$refs.file.click()">
                                            📎 Pilih File
                                        </button>

                                        <div class="flex-1 truncate rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                                            <span x-text="fileName || 'Belum ada file dipilih'"></span>
                                        </div>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-400">PDF/JPG/PNG, maks 5MB.</p>
                                </div>
                               
                            </div>
                        </template>

                        <div class="md:col-span-2">
                            <label class="text-xs text-slate-500">Catatan</label>
                            <input type="text" name="notes"
                                value="{{ old('notes') }}"
                                class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        </div>

                        <div class="md:col-span-2 flex justify-end">
                            <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700"
                                    title="{{ $canAddAttempt ? 'Tambah attempt lelang' : $attemptReason }}"
                                    {{ $canAddAttempt ? '' : 'disabled' }}>
                                ➕ Tambah Lelang
                            </button>
                        </div>
                    </form>

                    {{-- clear field ketika bukan LAKU (biar gak nyangkut) --}}
                    <script>
                    document.addEventListener('alpine:init', () => {
                        Alpine.directive('clear-if-not-laku', (el, { expression }, { evaluateLater, effect }) => {
                            const get = evaluateLater(expression);
                            effect(() => {
                                get(value => {
                                    if (!value) el.value = '';
                                });
                            });
                        });
                    });
                    </script>

                @endif

                {{-- list attempts --}}
                <div class="mt-4 space-y-3"> 
                    @forelse($auctions as $a)
                        <div class="rounded-xl border border-slate-200 p-4">
                            <div class="flex items-start justify-between gap-2">
                                <div>
                                    <div class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                                        @php
                                            $attemptNo = $a->attempt_no ?: $loop->iteration;
                                        @endphp

                                        <span class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 border border-indigo-200">
                                            Attempt ke-{{ $attemptNo }}
                                        </span>

                                        @if($a->auction_result)
                                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold {{ $resultBadge($a->auction_result) }}">
                                                {{ strtoupper($a->auction_result) }}
                                            </span>
                                        @endif

                                        {{-- ✅ badge kelengkapan jika LAKU --}}
                                        @if(($a->auction_result ?? '') === 'laku')
                                            @php
                                                $missing = [];
                                                if (empty($a->sold_value)) $missing[] = 'sold_value';
                                                if (empty($a->settlement_date)) $missing[] = 'settlement_date';
                                                if (empty($a->risalah_file_path)) $missing[] = 'risalah';
                                                $ok = empty($missing);
                                            @endphp
                                            <span class="inline-flex items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold
                                                {{ $ok ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200' }}">
                                                {{ $ok ? 'SETTLEMENT OK' : ('KURANG: '.implode(', ', $missing)) }}
                                            </span>
                                        @endif
                                    </div>

                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ $a->kpknl_office ?? '-' }} • Reg: {{ $a->registration_no ?? '-' }}
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    {{-- ✅ tombol edit: pakai details (di bawah) --}}
                                    <a href="#attempt-{{ $a->id }}"
                                    class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                                        ✏️ Edit
                                    </a>

                                    <form action="{{ route('legal-actions.ht.auctions.delete', [$action, $a]) }}" method="POST">
                                        @csrf @method('DELETE')
                                        <button onclick="return confirm('Hapus attempt ini?')"
                                                class="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                            🗑️ Hapus
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-3 text-sm">
                                <div>
                                    <div class="text-xs text-slate-500">Tgl Lelang</div>
                                    <div class="font-semibold text-slate-800">{{ $a->auction_date?->format('d/m/Y') ?? '-' }}</div>
                                </div>

                                <div>
                                    <div class="text-xs text-slate-500">Limit / Laku</div>
                                    <div class="font-semibold text-slate-800">
                                        {{ $a->limit_value ? number_format((float)$a->limit_value,0,',','.') : '-' }}
                                        /
                                        {{ $a->sold_value ? number_format((float)$a->sold_value,0,',','.') : '-' }}
                                    </div>
                                </div>

                                {{-- ✅ tambahan: settlement date --}}
                                <div>
                                    <div class="text-xs text-slate-500">Tgl Pelunasan (Settlement)</div>
                                    <div class="font-semibold text-slate-800">{{ $a->settlement_date?->format('d/m/Y') ?? '-' }}</div>
                                </div>

                                <div class="col-span-2">
                                    <div class="text-xs text-slate-500">Risalah</div>

                                    @if($a->risalah_file_path)
                                        <a href="{{ route('legal-actions.ht.auctions.risalah', [$action, $a]) }}"
                                        class="text-indigo-600 hover:underline"
                                        target="_blank">
                                            📄 Unduh Risalah
                                        </a>
                                    @else
                                        <span class="text-xs text-slate-500">-</span>
                                    @endif
                                </div>
                            </div>

                            {{-- ✅ FORM EDIT ATTEMPT (collapsible) --}}
                            <div id="attempt-{{ $a->id }}" class="mt-4">
                                <details class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <summary class="cursor-pointer select-none text-sm font-semibold text-slate-800">
                                        Edit Attempt #{{ $attemptNo }}
                                        <span class="ml-2 text-xs font-normal text-slate-500">(klik untuk buka/tutup)</span>
                                    </summary>

                                    <form class="mt-4 space-y-4"
                                        action="{{ route('legal-actions.ht.auctions.update', [$action, $a]) }}"
                                        method="POST"
                                        enctype="multipart/form-data">
                                        @csrf
                                        @method('PUT')

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600">KPKNL Office</label>
                                                <input type="text" name="kpknl_office"
                                                    value="{{ old('kpknl_office', $a->kpknl_office) }}"
                                                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-medium text-slate-600">No. Registrasi</label>
                                                <input type="text" name="registration_no"
                                                    value="{{ old('registration_no', $a->registration_no) }}"
                                                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-medium text-slate-600">Tgl Lelang</label>
                                                <input type="date" name="auction_date"
                                                    value="{{ old('auction_date', $a->auction_date?->format('Y-m-d')) }}"
                                                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
                                                    required>
                                            </div>

                                            <div>
                                                <label class="block text-xs font-medium text-slate-600">Hasil</label>
                                                <select name="auction_result"
                                                        class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
                                                        required>
                                                    @php
                                                        $res = old('auction_result', $a->auction_result);
                                                    @endphp
                                                    <option value="laku" {{ $res==='laku' ? 'selected' : '' }}>LAKU</option>
                                                    <option value="tidak_laku" {{ $res==='tidak_laku' ? 'selected' : '' }}>TIDAK LAKU</option>
                                                    <option value="batal" {{ $res==='batal' ? 'selected' : '' }}>BATAL</option>
                                                    <option value="tunda" {{ $res==='tunda' ? 'selected' : '' }}>TUNDA</option>
                                                </select>
                                                <div class="mt-1 text-[11px] text-slate-500">
                                                    * Jika LAKU: sold_value, settlement_date, risalah wajib.
                                                </div>
                                            </div>

                                            <div>
                                                <label class="block text-xs font-medium text-slate-600">Limit (Rp)</label>
                                                <input type="text" name="limit_value"
                                                    value="{{ old('limit_value', $a->limit_value ? number_format((float)$a->limit_value,0,',','.') : '') }}"
                                                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
                                                    placeholder="contoh: 1.000.000.000">
                                                <div class="mt-1 text-[11px] text-slate-500">Boleh pakai format rupiah, akan dibersihkan otomatis.</div>
                                            </div>

                                            <div>
                                                <label class="block text-xs font-medium text-slate-600">Nilai Laku (Rp)</label>
                                                <input type="text" name="sold_value"
                                                    value="{{ old('sold_value', $a->sold_value ? number_format((float)$a->sold_value,0,',','.') : '') }}"
                                                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
                                                    placeholder="contoh: 950.000.000">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-medium text-slate-600">Pemenang (opsional)</label>
                                                <input type="text" name="winner_name"
                                                    value="{{ old('winner_name', $a->winner_name) }}"
                                                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-medium text-slate-600">Tgl Pelunasan (Settlement)</label>
                                                <input type="date" name="settlement_date"
                                                    value="{{ old('settlement_date', $a->settlement_date?->format('Y-m-d')) }}"
                                                    class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm">
                                            </div>

                                            <div class="md:col-span-2">
                                                <label class="block text-xs font-medium text-slate-600">Risalah (PDF/JPG/PNG)</label>
                                                <input type="file" name="risalah_file"
                                                    class="mt-1 block w-full text-sm text-slate-700">
                                                @if($a->risalah_file_path)
                                                    <div class="mt-1 text-[11px] text-slate-500">
                                                        Sudah ada risalah. Upload file baru untuk mengganti.
                                                    </div>
                                                @endif
                                            </div>

                                            <div class="md:col-span-2">
                                                <label class="block text-xs font-medium text-slate-600">Catatan</label>
                                                <textarea name="notes" rows="2"
                                                        class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm"
                                                        placeholder="opsional">{{ old('notes', $a->notes) }}</textarea>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2">
                                            <button type="submit"
                                                    class="inline-flex items-center gap-2 rounded-lg border border-indigo-600 bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                                                💾 Simpan Perubahan
                                            </button>

                                            <div class="text-xs text-slate-500">
                                                Tip: Setelah settlement_date + risalah lengkap (hasil LAKU), kamu bisa klik tombol <b>SETTLED</b> di Ringkasan.
                                            </div>
                                        </div>
                                    </form>
                                </details>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                            Belum ada percobaan lelang.
                        </div>
                    @endforelse
                </div>
            @endif
        </div>

        {{-- RIGHT: Bawah tangan --}}
        <div class="rounded-xl border border-slate-200 p-4">
            <div>
                <div class="text-sm font-semibold text-slate-900">Penjualan Bawah Tangan</div>
                <div class="mt-1 text-xs text-slate-500">Hanya untuk metode bawah_tangan</div>
            </div>

            @if($method !== 'bawah_tangan')
                <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                    Metode saat ini bukan bawah tangan.
                </div>
            @else
                <form action="{{ route('legal-actions.ht.underhand.upsert', $action) }}" method="POST" enctype="multipart/form-data" class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                    @csrf
                    <div>
                        <label class="text-xs text-slate-500">Tgl Kesepakatan</label>
                        <input type="date" name="agreement_date" value="{{ old('agreement_date', $sale?->agreement_date?->format('Y-m-d')) }}"
                               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Nama Pembeli</label>
                        <input type="text" name="buyer_name" value="{{ old('buyer_name', $sale?->buyer_name) }}"
                               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Nilai Jual</label>
                        <input type="number" step="0.01" name="sale_value" value="{{ old('sale_value', $sale?->sale_value) }}"
                               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Metode Pembayaran</label>
                        <input type="text" name="payment_method" value="{{ old('payment_method', $sale?->payment_method) }}"
                               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Tgl Serah Terima</label>
                        <input type="date" name="handover_date" value="{{ old('handover_date', $sale?->handover_date?->format('Y-m-d')) }}"
                               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">File Kesepakatan</label>
                        <input type="file" name="agreement_file" class="mt-1 w-full text-sm">
                        @if($sale?->agreement_file_path)
                            <a class="text-indigo-600 hover:underline text-xs" href="{{ asset('storage/'.$sale->agreement_file_path) }}" target="_blank">Lihat file lama</a>
                        @endif
                    </div>
                    <div>
                        <label class="text-xs text-slate-500">Bukti Pembayaran</label>
                        <input type="file" name="proof_payment_file" class="mt-1 w-full text-sm">
                        @if($sale?->proof_payment_file_path)
                            <a class="text-indigo-600 hover:underline text-xs" href="{{ asset('storage/'.$sale->proof_payment_file_path) }}" target="_blank">Lihat file lama</a>
                        @endif
                    </div>
                    <div class="md:col-span-2">
                        <label class="text-xs text-slate-500">Catatan</label>
                        <input type="text" name="notes" value="{{ old('notes', $sale?->notes) }}"
                               class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    </div>
                    <div class="md:col-span-2 flex justify-end">
                        <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            💾 Simpan Bawah Tangan
                        </button>
                    </div>
                </form>
            @endif
        </div>
    </div>

    {{-- Timeline list --}}
    <div class="mt-6">
        <div class="text-sm font-semibold text-slate-900">Timeline Events</div>

        @if($events->isEmpty())
            <div class="mt-3 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                Belum ada event.
            </div>
        @else
            <div class="mt-3 space-y-3">
                @foreach($events as $ev)
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-slate-900">{{ $ev->event_type }}</div>
                                <div class="mt-1 text-xs text-slate-500">
                                    {{ $ev->event_at?->format('d/m/Y H:i') ?? '-' }}
                                    @if($ev->ref_no) • Ref: {{ $ev->ref_no }} @endif
                                </div>
                                @if($ev->notes)
                                    <div class="mt-2 text-sm text-slate-700 whitespace-pre-line">{{ $ev->notes }}</div>
                                @endif
                            </div>

                            <form action="{{ route('legal-actions.ht.events.delete', [$action, $ev]) }}" method="POST">
                                @csrf @method('DELETE')
                                <button onclick="return confirm('Hapus event ini?')"
                                        class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100">
                                    Hapus
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
