@php
    $exec = $action->htExecution;
    $locked = (bool) optional($exec)->locked_at;

    $methodOptions = [
        'parate' => 'Parate Eksekusi (Lelang KPKNL)',
        'pn' => 'Eksekusi via PN (Fiat + Lelang)',
        'bawah_tangan' => 'Penjualan di Bawah Tangan',
    ];

    // Field inti yang biasanya terkunci saat submitted+
    $isDisabled = fn() => $locked ? 'disabled' : '';
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <div class="flex items-start justify-between gap-4">
        <div>
            <div class="text-sm font-semibold text-slate-900">Data Objek & Dasar HT</div>
            <div class="mt-1 text-sm text-slate-600">
                Lengkapi data minimal agar bisa <span class="font-semibold">Prepared</span>.
            </div>
        </div>

        @if($locked)
            <span class="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800">
                🔒 Terkunci (submitted+)
            </span>
        @endif
    </div>

    <form action="{{ route('legal-actions.ht.upsert', $action) }}" method="POST" class="mt-5 space-y-4">
        @csrf
        {{-- supaya setelah save balik ke tab ini --}}
        <input type="hidden" name="return_url" value="{{ route('legal-actions.ht.show', $action).'?tab=execution' }}">
        <input type="hidden" name="appraisal_value" :value="appraisalRaw">
        <input type="hidden" name="outstanding_at_start" :value="outstandingRaw">

         {{-- Metode --}}
        <div class="rounded-xl border border-slate-200 p-4">
            <div class="text-xs text-slate-500 mb-2">Metode Eksekusi</div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                @foreach($methodOptions as $k => $lbl)
                    <label class="flex items-start gap-2 rounded-xl border border-slate-200 p-3 hover:bg-slate-50 cursor-pointer">
                        <input type="radio" name="method" value="{{ $k }}" class="mt-1"
                               {{ $isDisabled() }}
                               {{ old('method', $exec?->method) === $k ? 'checked' : '' }}>
                        <div>
                            <div class="text-sm font-semibold text-slate-900">{{ $lbl }}</div>
                            <div class="text-xs text-slate-500">
                                @if($k==='parate') Tanpa putusan PN, melalui KPKNL. @endif
                                @if($k==='pn') Melalui permohonan & fiat PN, lalu eksekusi. @endif
                                @if($k==='bawah_tangan') Berdasarkan kesepakatan debitur & harga wajar. @endif
                            </div>
                        </div>
                    </label>
                @endforeach
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="text-xs text-slate-500">Tanggal Dasar Wanprestasi (opsional)</label>
                <input type="date" name="basis_default_at"
                       value="{{ old('basis_default_at', $exec?->basis_default_at?->format('Y-m-d')) }}"
                       {{ $isDisabled() }}
                       class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
            </div>
            <div>
                <label class="text-xs text-slate-500">Ringkasan Objek (opsional)</label>
                <input type="text" name="collateral_summary"
                       value="{{ old('collateral_summary', $exec?->collateral_summary) }}"
                       {{ $isDisabled() }}
                       class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500"
                       placeholder="Contoh: Tanah & bangunan 120m2, lokasi ...">
            </div>
        </div>

        <div class="rounded-xl border border-slate-200 p-4">
            <div class="text-sm font-semibold text-slate-900">Data Sertifikat & Objek</div>
            <div class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-slate-500">Jenis Sertifikat (SHM/SHGB/dll)</label>
                    <input type="text" name="land_cert_type"
                           value="{{ old('land_cert_type', $exec?->land_cert_type) }}"
                           {{ $isDisabled() }}
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="text-xs text-slate-500">No. Sertifikat</label>
                    <input type="text" name="land_cert_no"
                           value="{{ old('land_cert_no', $exec?->land_cert_no) }}"
                           {{ $isDisabled() }}
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="text-xs text-slate-500">Nama Pemilik</label>
                    <input type="text" name="owner_name"
                           value="{{ old('owner_name', $exec?->owner_name) }}"
                           {{ $isDisabled() }}
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="text-xs text-slate-500">Alamat Objek</label>
                    <input type="text" name="object_address"
                           value="{{ old('object_address', $exec?->object_address) }}"
                           {{ $isDisabled() }}
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-slate-500">No. APHT (opsional)</label>
                    <input type="text" name="ht_deed_no"
                           value="{{ old('ht_deed_no', $exec?->ht_deed_no) }}"
                           {{ $isDisabled() }}
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="text-xs text-slate-500">No. Sertifikat HT (opsional)</label>
                    <input type="text" name="ht_cert_no"
                           value="{{ old('ht_cert_no', $exec?->ht_cert_no) }}"
                           {{ $isDisabled() }}
                           class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
            </div>
        </div>

        {{-- =========================
            NILAI (RUPIAH FORMAT)
        ========================= --}}

        @php
            // value dari DB / old()
            $appraisalRaw   = old('appraisal_value', $exec?->appraisal_value);
            $outstandingRaw = old('outstanding_at_start', $exec?->outstanding_at_start);
        @endphp

        <div
        x-data="rupiahForm({
            appraisal: @js($appraisalRaw),
            outstanding: @js($outstandingRaw),
        })"
        class="grid grid-cols-1 md:grid-cols-2 gap-4"
        >
            {{-- Nilai Taksasi --}}
            <div>
                <label class="text-sm font-medium text-slate-700">Nilai Taksasi (Rp)</label>

                {{-- hidden raw (yang dikirim ke backend) --}}
                <input type="hidden" name="appraisal_value" :value="appraisalRaw">

                <div class="mt-2 relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500 text-sm">Rp</span>

                    <input
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        class="w-full rounded-xl border border-slate-200 bg-white px-10 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-200"
                        placeholder="0"
                        x-model="appraisalDisplay"
                        @input="onInput('appraisal', $event.target.value)"
                        @blur="onBlur('appraisal')"
                    >
                </div>

                @error('appraisal_value')
                    <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                @enderror
            </div>

            {{-- Outstanding --}}
            <div>
                <label class="text-sm font-medium text-slate-700">Outstanding Saat Mulai (Rp)</label>

                {{-- hidden raw (yang dikirim ke backend) --}}
                <input type="hidden" name="outstanding_at_start" :value="outstandingRaw">

                <div class="mt-2 relative">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500 text-sm">Rp</span>

                    <input
                        type="text"
                        inputmode="numeric"
                        autocomplete="off"
                        class="w-full rounded-xl border border-slate-200 bg-white px-10 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-200"
                        placeholder="0"
                        x-model="outstandingDisplay"
                        @input="onInput('outstanding', $event.target.value)"
                        @blur="onBlur('outstanding')"
                    >
                </div>

                @error('outstanding_at_start')
                    <div class="mt-1 text-xs text-rose-600">{{ $message }}</div>
                @enderror
            </div>
        </div>


        <div class="rounded-xl border border-slate-200 p-4">
            <label class="text-xs text-slate-500">Catatan</label>
            <textarea name="notes" rows="4"
                      class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500"
                      placeholder="Catatan internal eksekusi...">{{ old('notes', $exec?->notes) }}</textarea>
            @if($locked)
                <div class="mt-2 text-xs text-slate-500">Saat terkunci, hanya catatan yang bisa diubah.</div>
            @endif
        </div>

        <div class="flex items-center justify-end gap-2">
            <button type="submit"
                    class="inline-flex items-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                💾 Simpan
            </button>
        </div>
    </form>
</div>

{{-- Script helper (tempel sekali di bawah file / di section scripts) --}}
<script>
function rupiahForm(init = {}) {
const toDigits = (v) => (String(v ?? '').match(/\d+/g) || []).join('');
const formatID = (digits) => {
    digits = toDigits(digits);
    if (!digits) return '';
    // 10000000 -> 10.000.000
    return digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
};

const normalizeRaw = (v) => {
  // ambil angka utama sebelum titik/koma (kalau ada)
  const s = String(v ?? '');
  const main = s.split(/[.,]/)[0];     // "1000000000.00" -> "1000000000"
  const d = toDigits(main);
  return d ? Number(d) : null;
};


return {
    // raw numeric (yang dikirim)
    appraisalRaw: normalizeRaw(init.appraisal),
    outstandingRaw: normalizeRaw(init.outstanding),

    // display (yang dilihat)
    appraisalDisplay: '',
    outstandingDisplay: '',

    init() {
    this.appraisalDisplay = formatID(this.appraisalRaw);
    this.outstandingDisplay = formatID(this.outstandingRaw);
    },

    onInput(kind, value) {
    const digits = toDigits(value);

    if (kind === 'appraisal') {
        this.appraisalRaw = digits ? Number(digits) : null;
        this.appraisalDisplay = formatID(digits);
    } else {
        this.outstandingRaw = digits ? Number(digits) : null;
        this.outstandingDisplay = formatID(digits);
    }
    },

    onBlur(kind) {
    // pastikan format rapi saat keluar input
    if (kind === 'appraisal') this.appraisalDisplay = formatID(this.appraisalRaw);
    else this.outstandingDisplay = formatID(this.outstandingRaw);
    }
}
}
</script>
