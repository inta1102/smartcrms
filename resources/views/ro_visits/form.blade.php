@extends('layouts.app')

@section('title','Input LKH Visit')

@section('content')
@php
  $backUrl = $back ?? request('back') ?? url()->previous();
  $isDone = (($visit->status ?? '') === 'done');
  $visitDate = !empty($visit->visit_date) ? \Carbon\Carbon::parse($visit->visit_date)->format('d/m/Y') : '-';
@endphp

<div class="max-w-xl mx-auto p-3 sm:p-4 space-y-3">

  {{-- Header --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-3">
    <div class="flex items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="text-lg font-extrabold text-slate-900 leading-tight">📝 Input LKH Visit</div>
        <div class="text-xs text-slate-500 mt-1">
          Visit: <b>{{ $visitDate }}</b>
          <span class="mx-1">•</span>
          Status:
          <b class="{{ $isDone ? 'text-emerald-600' : 'text-slate-700' }}">
            {{ strtoupper($visit->status ?? 'planned') }}
          </b>
        </div>
      </div>

      <a href="{{ $backUrl }}"
         class="shrink-0 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-white">
        ← Kembali
      </a>
    </div>

    @if($isDone)
      <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-emerald-800 text-sm">
        ✅ Visit sudah <b>DONE</b>. Saat ini form dikunci (nanti kalau perlu kita buat menu <b>Edit LKH</b> khusus).
      </div>
    @endif
  </div>

  {{-- Flash & errors --}}
  @if(session('success'))
    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-emerald-800 text-sm">
      {{ session('success') }}
    </div>
  @endif

  @if($errors->any())
    <div class="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-rose-800 text-sm space-y-1">
      <div class="font-bold">Ada error:</div>
      <ul class="list-disc pl-5">
        @foreach($errors->all() as $e)
          <li>{{ $e }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  {{-- Debitur --}}
  <div class="rounded-2xl border border-slate-200 bg-white p-3">
    <div class="flex items-start justify-between gap-2">
      <div>
        <div class="text-sm font-extrabold text-slate-900">Debitur</div>
        <div class="text-xs text-slate-500 mt-0.5">Ringkas info rekening & posisi terakhir.</div>
      </div>
      <div class="text-xs text-slate-500 text-right">
        <div>No Rek</div>
        <div class="font-mono font-bold text-slate-900">{{ $visit->account_no ?? '-' }}</div>
      </div>
    </div>

    <div class="mt-2 text-sm text-slate-700">
      <div class="font-bold text-slate-900">{{ $deb->customer_name ?? '-' }}</div>
    </div>

    <div class="mt-3 grid grid-cols-3 gap-2 text-xs">
      <div class="rounded-2xl bg-slate-50 border border-slate-200 p-2">
        <div class="text-slate-500">OS</div>
        <div class="text-sm font-extrabold text-slate-900 mt-0.5">
          Rp {{ number_format((int)($deb->outstanding ?? 0),0,',','.') }}
        </div>
      </div>
      <div class="rounded-2xl bg-slate-50 border border-slate-200 p-2">
        <div class="text-slate-500">DPD</div>
        <div class="text-sm font-extrabold text-slate-900 mt-0.5">{{ (int)($deb->dpd ?? 0) }}</div>
      </div>
      <div class="rounded-2xl bg-slate-50 border border-slate-200 p-2">
        <div class="text-slate-500">Kolek</div>
        <div class="text-sm font-extrabold text-slate-900 mt-0.5">{{ $deb->kolek ?? '-' }}</div>
      </div>
    </div>
  </div>

  <form method="POST" action="{{ route('ro_visits.store') }}" enctype="multipart/form-data" class="space-y-3">
    @csrf
    <input type="hidden" name="visit_id" value="{{ (int)($visit->id ?? 0) }}">
    <input type="hidden" name="account_no" value="{{ $visit->account_no ?? '' }}">
    <input type="hidden" name="back" value="{{ $backUrl }}">

    {{-- Koordinat --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3">
      <div class="flex items-start justify-between gap-2">
        <div>
          <div class="text-sm font-extrabold text-slate-900">Koordinat (opsional)</div>
          <div class="text-xs text-slate-500 mt-0.5">Klik tombol di bawah untuk ambil lokasi.</div>
        </div>
        <div id="geoBadge" class="text-[11px] px-2 py-1 rounded-full border border-slate-200 bg-slate-50 text-slate-600">
          belum diambil
        </div>
      </div>

      <div class="grid grid-cols-2 gap-2 mt-3">
        <input type="text" name="lat" id="lat" inputmode="decimal" placeholder="Latitude"
               value="{{ old('lat', $visit->lat ?? '') }}"
               class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white"
               {{ $isDone ? 'disabled' : '' }}>
        <input type="text" name="lng" id="lng" inputmode="decimal" placeholder="Longitude"
               value="{{ old('lng', $visit->lng ?? '') }}"
               class="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white"
               {{ $isDone ? 'disabled' : '' }}>
      </div>

      <button type="button" id="btnGeo"
              {{ $isDone ? 'disabled' : '' }}
              class="mt-2 w-full rounded-xl px-4 py-3 text-sm font-extrabold
                     {{ $isDone ? 'bg-slate-200 text-slate-500' : 'bg-slate-900 text-white active:scale-[0.99]' }}">
        📍 Ambil Lokasi
      </button>

      <div id="geoMsg" class="text-xs text-slate-500 mt-2"></div>
    </div>

    {{-- LKH --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3">
      <div class="flex items-start justify-between gap-2">
        <div>
          <div class="text-sm font-extrabold text-slate-900">Hasil Kunjungan (LKH)</div>
          <div class="text-xs text-slate-500 mt-0.5">Singkat, jelas, dan ada next action.</div>
        </div>
        <div class="text-[11px] text-slate-500">
          <span id="lkhCount">0</span>/1500
        </div>
      </div>

      {{-- helper template cepat --}}
      <div class="mt-3 grid grid-cols-2 gap-2">
        <button type="button" id="tplRingkas"
                {{ $isDone ? 'disabled' : '' }}
                class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700">
          Isi Template Ringkas
        </button>
        <button type="button" id="tplJanjiBayar"
                {{ $isDone ? 'disabled' : '' }}
                class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700">
          Isi Template Janji Bayar
        </button>
      </div>

      <textarea name="lkh_note" id="lkh_note" rows="9" maxlength="1500"
                class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white leading-relaxed"
                placeholder="Contoh:
• Debitur ditemui (ya/tidak), kondisi usaha/rumah
• Komitmen bayar: tanggal & nominal
• Kendala/penyebab
• Next action: kunjungan ulang / telepon / surat / restruk / eskalasi TL"
                {{ $isDone ? 'disabled' : '' }}>{{ old('lkh_note', $visit->lkh_note ?? '') }}</textarea>

      <div class="mt-2 text-xs text-slate-500">
        Tips: tulis pakai poin “•” biar cepat dan kebaca TL/Kasi.
      </div>
    </div>

    {{-- Foto --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-3">
      <div class="flex items-start justify-between gap-2">
        <div>
          <div class="text-sm font-extrabold text-slate-900">Foto Bukti (maks 6)</div>
          <div class="text-xs text-slate-500 mt-0.5">Di HP bisa langsung buka kamera belakang.</div>
        </div>
        <div class="text-[11px] text-slate-500">opsional</div>
      </div>

      <input type="file"
             name="photos[]"
             id="photos"
             multiple
             accept="image/*"
             capture="environment"
             {{ $isDone ? 'disabled' : '' }}
             class="mt-2 block w-full text-sm file:mr-3 file:rounded-xl file:border-0 file:bg-slate-900 file:px-4 file:py-2 file:text-white file:font-semibold">

      <div id="preview" class="mt-3 grid grid-cols-3 gap-2"></div>

      @if(($photos ?? collect())->count())
        <div class="mt-3 grid grid-cols-3 gap-2">
          @foreach($photos as $p)
            <a href="{{ $p->path }}" target="_blank" class="block">
              <img src="{{ $p->path }}" class="w-full h-24 object-cover rounded-xl border border-slate-200" />
            </a>
          @endforeach
        </div>
      @endif

      <div class="mt-2 text-xs text-slate-500">
        Kalau file kepanjangan/berat, nanti kita bisa auto-kompres server-side (tahap berikutnya).
      </div>
    </div>

    <button type="submit"
            {{ $isDone ? 'disabled' : '' }}
            class="w-full rounded-2xl px-4 py-4 text-white font-extrabold text-base
                   {{ $isDone ? 'bg-slate-300' : 'bg-emerald-600 hover:bg-emerald-700 active:scale-[0.99]' }}">
      ✅ Simpan Visit (DONE)
    </button>

    <div class="text-[11px] text-slate-500 text-center">
      Setelah disimpan, status jadi <b>DONE</b> dan checkbox plan akan terkunci.
    </div>
  </form>

</div>

<script>
  // ===== Geolocation =====
  const btnGeo = document.getElementById('btnGeo');
  const geoMsg = document.getElementById('geoMsg');
  const geoBadge = document.getElementById('geoBadge');

  function setGeoBadge(ok){
    if (!geoBadge) return;
    if (ok){
      geoBadge.textContent = 'lokasi terisi';
      geoBadge.className = 'text-[11px] px-2 py-1 rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700';
    } else {
      geoBadge.textContent = 'belum diambil';
      geoBadge.className = 'text-[11px] px-2 py-1 rounded-full border border-slate-200 bg-slate-50 text-slate-600';
    }
  }

  // initial badge
  const latInit = (document.getElementById('lat')?.value || '').trim();
  const lngInit = (document.getElementById('lng')?.value || '').trim();
  setGeoBadge(!!latInit && !!lngInit);

  btnGeo?.addEventListener('click', () => {
    if (!geoMsg) return;
    geoMsg.textContent = 'Mengambil lokasi...';

    if (!navigator.geolocation) {
      geoMsg.textContent = 'Browser tidak mendukung geolocation.';
      return;
    }

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        document.getElementById('lat').value = Number(lat).toFixed(7);
        document.getElementById('lng').value = Number(lng).toFixed(7);
        geoMsg.textContent = 'Lokasi terisi ✅';
        setGeoBadge(true);
      },
      (err) => {
        geoMsg.textContent = 'Gagal ambil lokasi: ' + (err.message || err);
      },
      { enableHighAccuracy:true, timeout:8000, maximumAge:0 }
    );
  });

  // ===== LKH counter + template =====
  const ta = document.getElementById('lkh_note');
  const countEl = document.getElementById('lkhCount');

  function refreshCount(){
    if (!ta || !countEl) return;
    countEl.textContent = String((ta.value || '').length);
  }
  ta?.addEventListener('input', refreshCount);
  refreshCount();

  function fillIfEmpty(text){
    if (!ta) return;
    const cur = (ta.value || '').trim();
    if (cur !== '') return; // jangan overwrite
    ta.value = text;
    refreshCount();
    ta.focus();
  }

  document.getElementById('tplRingkas')?.addEventListener('click', () => {
    fillIfEmpty(
`• Debitur ditemui: (ya/tidak)
• Kondisi: ...
• Kendala: ...
• Komitmen bayar: tanggal .... / nominal ....
• Bukti/hasil: ...
• Next action: ...`
    );
  });

  document.getElementById('tplJanjiBayar')?.addEventListener('click', () => {
    fillIfEmpty(
`• Debitur ditemui: ya
• Komitmen bayar: tanggal .... nominal ....
• Kesepakatan: (transfer/tunai) + bukti yang dijanjikan
• Catatan: ...
• Next action: follow up H-1 & cek realisasi`
    );
  });

  // ===== Photo preview (client-side) =====
  const inputPhotos = document.getElementById('photos');
  const preview = document.getElementById('preview');

  function clearPreview(){
    if (!preview) return;
    preview.innerHTML = '';
  }

  function addThumb(file){
    if (!preview) return;
    const url = URL.createObjectURL(file);
    const wrap = document.createElement('div');
    wrap.className = 'relative';

    const img = document.createElement('img');
    img.src = url;
    img.className = 'w-full h-24 object-cover rounded-xl border border-slate-200';
    img.onload = () => URL.revokeObjectURL(url);

    wrap.appendChild(img);
    preview.appendChild(wrap);
  }

  inputPhotos?.addEventListener('change', () => {
    clearPreview();
    const files = Array.from(inputPhotos.files || []);
    files.slice(0, 6).forEach(addThumb);
  });
</script>
@endsection
