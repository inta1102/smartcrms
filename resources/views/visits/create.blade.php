@extends('layouts.app')

@section('title', 'Kunjungan Lapangan - ' . $loan->customer_name)

@section('content')
<div class="w-full max-w-3xl space-y-4">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl md:text-2xl font-semibold text-slate-800">
                Kunjungan Lapangan — {{ $loan->customer_name }}
            </h1>
            <p class="text-xs md:text-sm text-slate-500 mt-1">
                Rek: {{ $loan->account_no }} • CIF: {{ $loan->cif }} • DPD: {{ $loan->dpd }}
            </p>
        </div>

        <a href="{{ route('dashboard.ao.agenda', $loan->ao_code) }}"
           class="hidden sm:inline-flex px-3 py-1.5 rounded-lg border border-slate-300 text-xs text-slate-700 hover:bg-slate-50">
            Kembali ke Agenda
        </a>
    </div>

    {{-- Panel ringkasan kasus & riwayat kunjungan --}}
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4 space-y-3">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-[11px] uppercase tracking-wide text-slate-500">
                    Ringkasan Kasus
                </div>
                <div class="mt-1 text-xs text-slate-600">
                    Kolek: <span class="font-semibold">{{ $loan->kolek }}</span>
                    • DPD: <span class="font-semibold">{{ $loan->dpd }}</span>
                    @if (!empty($case->priority))
                        • Prioritas:
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px]
                            @if($case->priority === 'critical') bg-red-100 text-red-700
                            @elseif($case->priority === 'high') bg-amber-100 text-amber-700
                            @else bg-emerald-100 text-emerald-700 @endif">
                            {{ strtoupper($case->priority) }}
                        </span>
                    @endif
                </div>
            </div>

            <div class="text-right">
                <div class="text-[11px] uppercase tracking-wide text-slate-500">
                    Status Agenda
                </div>
                <div class="mt-1 text-xs text-slate-600">
                    Jadwal: <span class="font-semibold">{{ $schedule->scheduled_at?->format('d M Y') }}</span><br>
                    Jenis: <span class="font-semibold">{{ $schedule->title ?? 'Kunjungan Lapangan' }}</span>
                </div>
            </div>
        </div>

        @if(isset($recentVisits) && $recentVisits->isNotEmpty())
            <div class="pt-3 border-t border-slate-100">
                <div class="flex items-center justify-between mb-2">
                    <div class="text-[11px] uppercase tracking-wide text-slate-500">
                        Riwayat Kunjungan Terakhir
                    </div>
                    <div class="text-[11px] text-slate-400">
                        {{ $recentVisits->count() }} kunjungan terakhir
                    </div>
                </div>

                <div class="space-y-2 max-h-40 overflow-y-auto pr-1">
                    @foreach ($recentVisits as $visit)
                        <div class="flex items-start gap-2 text-xs">
                            <div class="mt-1 w-1 h-1 rounded-full bg-msa-blue"></div>
                            <div class="flex-1">
                                <div class="flex items-center justify-between">
                                    <div class="font-semibold text-slate-800">
                                        {{ $visit->visited_at?->format('d M Y, H:i') }}
                                    </div>
                                    <div class="text-[11px] text-slate-500">
                                        {{ $visit->user?->name ?? 'AO' }}
                                    </div>
                                </div>
                                @if($visit->agreement)
                                    <div class="text-[11px] text-emerald-700 mt-0.5">
                                        Kesepakatan: {{ $visit->agreement }}
                                    </div>
                                @endif
                                @if($visit->location_note)
                                    <div class="text-[11px] text-slate-500 mt-0.5">
                                        Lokasi: {{ $visit->location_note }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="pt-3 border-t border-slate-100 text-[11px] text-slate-500">
                Belum ada riwayat kunjungan yang tersimpan untuk kasus ini.
            </div>
        @endif
    </div>

    @if ($errors->any())
        <div class="p-3 rounded-lg bg-red-50 border border-red-200 text-xs text-red-700">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST"
        action="{{ route('visits.store', $schedule->id) }}"
        enctype="multipart/form-data"
        class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 space-y-6">
        @csrf
        
        <input type="hidden" name="ao_agenda_id" value="{{ old('ao_agenda_id', $agendaId ?? request('agenda')) }}">

        {{-- SECTION: Waktu & Lokasi --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            {{-- Waktu --}}
            <div>
                <label class="text-xs font-semibold text-slate-600">
                    Waktu Kunjungan <span class="text-red-500">*</span>
                </label>
                <input type="datetime-local" name="visited_at"
                    value="{{ old('visited_at', now()->format('Y-m-d\TH:i')) }}"
                    class="w-full mt-1 border border-slate-300 rounded-lg p-2.5 text-sm
                            focus:ring-2 focus:ring-msa-blue/40 focus:border-msa-blue" required>
            </div>

            {{-- Lokasi --}}
            <div>
                <label class="text-xs font-semibold text-slate-600 block">
                    Lokasi (Koordinat)
                </label>

                <div class="grid grid-cols-2 gap-2 mt-1">
                    <input type="text" id="lat-input" name="latitude"
                        placeholder="Lat"
                        class="border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-msa-blue/40">

                    <input type="text" id="lng-input" name="longitude"
                        placeholder="Lng"
                        class="border border-slate-300 rounded-lg p-2.5 text-sm focus:ring-2 focus:ring-msa-blue/40">
                </div>

                <div class="flex gap-2 mt-2">
                    <button type="button" id="btn-locate"
                            class="px-3 py-2 rounded-lg bg-msa-blue text-white text-xs font-semibold hover:bg-msa-blue/90">
                        Ambil Lokasi
                    </button>

                    {{-- Preview Map --}}
                    <button type="button" id="btn-map"
                            class="px-3 py-2 rounded-lg border border-slate-300 text-xs text-slate-700 hover:bg-slate-50">
                        Lihat Map
                    </button>
                </div>
            </div>
        </div>


        {{-- SECTION: Keterangan Lokasi --}}
        <div>
            <label class="text-xs font-semibold text-slate-600">
                Keterangan Lokasi (opsional)
            </label>
            <input type="text" name="location_note"
                value="{{ old('location_note') }}"
                placeholder="Contoh: Rumah debitur, usaha di Pasar Giwangan, dsb."
                class="w-full mt-1 border border-slate-300 rounded-lg p-3 text-sm
                        focus:ring-2 focus:ring-msa-blue/40 focus:border-msa-blue">
        </div>


        {{-- SECTION: Notulen --}}
        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="text-xs font-semibold text-slate-600">
                    Hasil Kunjungan / Notulen <span class="text-red-500">*</span>
                </label>

                {{-- Tombol Template --}}
                <button type="button" id="btn-template"
                        class="text-xs text-msa-blue hover:underline">
                    Gunakan Template
                </button>
            </div>

            <textarea name="notes" id="notes-textarea"
                class="w-full border border-slate-300 rounded-lg p-3 text-sm min-h-[130px]
                    focus:ring-2 focus:ring-msa-blue/40 focus:border-msa-blue"
                required>{{ old('notes') }}</textarea>

            <div class="mt-1 text-[11px] text-slate-400 text-right">
                <span id="notes-count">0</span> karakter
            </div>
        </div>

        {{-- SECTION: Kesepakatan --}}
        <div>
            <label class="text-xs font-semibold text-slate-600">
                Ringkasan Kesepakatan (opsional)
            </label>
            <input type="text" name="agreement"
                placeholder="Misal: debitur setuju setor 1 juta per minggu"
                class="w-full mt-1 border border-slate-300 rounded-lg p-3 text-sm
                        focus:ring-2 focus:ring-msa-blue/40 focus:border-msa-blue">
        </div>


        {{-- SECTION: Foto --}}
        <div>
            <label class="text-xs font-semibold text-slate-600">Foto (opsional)</label>

            <div class="mt-2 flex flex-wrap items-center gap-2">
                {{-- Upload file --}}
                <button type="button"
                class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                onclick="document.getElementById('photo-upload').click()">
                📁 Upload File
                </button>

                {{-- Kamera --}}
                <button type="button"
                class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                onclick="document.getElementById('photo-camera').click()">
                📷 Ambil dari Kamera
                </button>

                <span id="photo-filename" class="text-xs text-slate-500"></span>
            </div>

            <p class="mt-2 text-[11px] text-slate-500">
                Bisa foto usaha, rumah, atau bukti pertemuan. Maks 2 MB.
            </p>

            {{-- input hidden --}}
            <input type="file" id="photo-upload" name="photo_upload" accept="image/*" class="hidden">
            <input type="file" id="photo-camera" name="photo_camera" accept="image/*" capture="environment" class="hidden">

            {{-- preview --}}
            <img id="photo-preview" class="mt-3 hidden w-40 rounded-lg border border-slate-200 shadow" alt="preview foto">
        </div>

        <script>
            (function(){
            const up = document.getElementById('photo-upload');
            const cam = document.getElementById('photo-camera');
            const prev = document.getElementById('photo-preview');
            const fn = document.getElementById('photo-filename');

            function handle(file) {
                if (!file) return;
                fn.textContent = file.name;

                const url = URL.createObjectURL(file);
                prev.src = url;
                prev.classList.remove('hidden');
            }

            up?.addEventListener('change', () => handle(up.files?.[0]));
            cam?.addEventListener('change', () => handle(cam.files?.[0]));
            })();
        </script>


        {{-- SECTION: Next Action --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label class="text-xs font-semibold text-slate-600">Next Action</label>
            <input type="text" name="next_action" id="next-action-input"
                placeholder="Misal: follow-up pembayaran, kirim SP1, dll"
                class="w-full mt-1 border border-slate-300 rounded-lg p-3 text-sm
                        focus:ring-2 focus:ring-msa-blue/40 focus:border-msa-blue">

            {{-- Quick chips --}}
            <div class="mt-2 flex flex-wrap gap-2">
                @php
                    $chips = [
                        'Follow-up komitmen pembayaran',
                        'Monitoring setoran berikutnya',
                        'Kirim SP1',
                        'Kunjungan ulang cek usaha',
                    ];
                @endphp
                @foreach ($chips as $chip)
                    <button type="button"
                            class="chip-next-action px-2.5 py-1 rounded-full border border-slate-300
                                bg-slate-50 text-[11px] text-slate-700 hover:bg-msa-blue/5 hover:border-msa-blue">
                        {{ $chip }}
                    </button>
                @endforeach
            </div>
        </div>

            <div>
                <label class="text-xs font-semibold text-slate-600">
                    Due Date Next Action
                </label>
                <input type="date" name="next_action_due"
                    class="w-full mt-1 border border-slate-300 rounded-lg p-2.5 text-sm
                            focus:ring-2 focus:ring-msa-blue/40 focus:border-msa-blue">
            </div>
        </div>


        {{-- ACTION BUTTONS --}}
        <div class="flex justify-end gap-2 pt-4 border-t border-slate-100">
            <a href="{{ route('dashboard.ao.agenda', $loan->ao_code) }}"
                class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs text-slate-700 hover:bg-slate-50">
                Batal
            </a>

            <button type="submit"
                    class="px-5 py-1.5 rounded-lg bg-msa-blue text-white text-xs font-semibold hover:bg-msa-blue/90 shadow">
                Simpan Kunjungan
            </button>
        </div>

    </form>

</div>

{{-- Script geolocation sederhana --}}
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const latInput      = document.getElementById('lat-input');
    const lngInput      = document.getElementById('lng-input');
    const btnLocate     = document.getElementById('btn-locate');
    const btnMap        = document.getElementById('btn-map');
    const btnTemplate   = document.getElementById('btn-template');
    const notesTextarea = document.getElementById('notes-textarea');
    const notesCount    = document.getElementById('notes-count');
    const photoInput    = document.getElementById('photo-input');
    const photoPreview  = document.getElementById('photo-preview');
    const nextAction    = document.getElementById('next-action-input');
    const chips         = document.querySelectorAll('.chip-next-action');

    // Ambil Lokasi
    if (btnLocate && latInput && lngInput) {
        btnLocate.addEventListener('click', function () {
            if (!navigator.geolocation) {
                alert('Browser tidak mendukung geolocation.');
                return;
            }

            navigator.geolocation.getCurrentPosition(function (pos) {
                latInput.value = pos.coords.latitude.toFixed(7);
                lngInput.value = pos.coords.longitude.toFixed(7);
            }, function (err) {
                alert('Gagal mengambil lokasi: ' + err.message);
            });
        });
    }

    // Lihat Map
    if (btnMap && latInput && lngInput) {
        btnMap.addEventListener('click', function () {
            const lat = latInput.value;
            const lng = lngInput.value;

            if (!lat || !lng) {
                alert('Koordinat belum diisi.');
                return;
            }

            window.open(`https://www.google.com/maps?q=${lat},${lng}`, '_blank');
        });
    }

    // Template Notulen
    if (btnTemplate && notesTextarea && notesCount) {
        btnTemplate.addEventListener('click', function () {
            const tmpl = `📌 Kondisi Usaha:
- 

📌 Pendapatan Rata-rata:
- 

📌 Kendala Debitur:
- 

📌 Respons Debitur:
- 

📌 Komitmen / Kesepakatan:
- 
`;
            notesTextarea.value = tmpl;
            notesTextarea.focus();
            notesCount.textContent = tmpl.length;
        });

        // live counter
        notesTextarea.addEventListener('input', function () {
            notesCount.textContent = this.value.length;
        });
        // initial
        notesCount.textContent = notesTextarea.value.length;
    }

    // Foto Preview
    if (photoInput && photoPreview) {
        photoInput.addEventListener('change', function (e) {
            const file = e.target.files[0];
            if (!file) return;
            const url = URL.createObjectURL(file);
            photoPreview.src = url;
            photoPreview.classList.remove('hidden');
        });
    }

    // Quick chips next action
    if (nextAction && chips.length) {
        chips.forEach(chip => {
            chip.addEventListener('click', function () {
                const text = this.textContent.trim();
                // kalau kosong → isi, kalau sudah ada → tambahkan dengan pemisah
                if (!nextAction.value) {
                    nextAction.value = text;
                } else if (!nextAction.value.includes(text)) {
                    nextAction.value = nextAction.value + '; ' + text;
                }
                nextAction.focus();
            });
        });
    }
});
</script>
@endpush

@endsection
