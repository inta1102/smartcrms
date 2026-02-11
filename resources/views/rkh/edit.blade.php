@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">

  <div class="flex items-start justify-between gap-3">
    <div>
      <h1 class="text-2xl font-bold">Edit RKH</h1>
      <p class="text-sm text-slate-600 mt-1">
        Tanggal: <b>{{ $rkh->tanggal->format('d-m-Y') }}</b> |
        Status: <b>{{ strtoupper($rkh->status) }}</b>
      </p>
      <p class="text-xs text-slate-500 mt-1">
        Tujuan otomatis mengikuti Jenis. Jam tidak boleh overlap.
      </p>
    </div>

    <div class="flex items-center gap-2">
      <a href="{{ route('rkh.show', $rkh->id) }}" class="px-3 py-2 rounded border">Kembali</a>
    </div>
  </div>

  @if ($errors->any())
    <div class="mt-4 p-3 border rounded bg-red-50 text-red-700 text-sm">
      <div class="font-semibold mb-1">Ada error:</div>
      <ul class="list-disc ml-5">
        @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
      </ul>
    </div>
  @endif

  @php
    // Prioritas old() jika ada error; kalau tidak, pakai data DB
    $rows = old('items');
    if (!is_array($rows)) {
      $rows = $rkh->details->map(function($d){
        return [
          'jam_mulai' => substr($d->jam_mulai,0,5),
          'jam_selesai' => substr($d->jam_selesai,0,5),

          'nasabah_id' => $d->nasabah_id,
          'account_no' => $d->account_no, // ✅ tambah ini
          'nama_nasabah' => $d->nama_nasabah,

          'kolektibilitas' => $d->kolektibilitas,
          'jenis_kegiatan' => $d->jenis_kegiatan,
          'tujuan_kegiatan' => $d->tujuan_kegiatan,
          'area' => $d->area,
          'catatan' => $d->catatan,
          'networking' => $d->networking ? [
            'nama_relasi' => $d->networking->nama_relasi,
            'jenis_relasi' => $d->networking->jenis_relasi,
            'potensi' => $d->networking->potensi,
            'follow_up' => $d->networking->follow_up,
          ] : null,
        ];
      })->values()->toArray();

    }
  @endphp

  <form class="mt-4" method="POST" action="{{ route('rkh.update', $rkh->id) }}" id="rkhForm">
    @csrf
    @method('PUT')

    {{-- tanggal hidden (tetap di header, tidak diedit di sini) --}}
    <input type="hidden" name="tanggal" value="{{ $rkh->tanggal->toDateString() }}">

    {{-- MOBILE: cards --}}
    <div class="sm:hidden space-y-3" id="cards">
      @foreach($rows as $i => $it)
        @include('rkh.partials.edit-card', ['i' => $i, 'it' => $it, 'jenis' => $jenis])
      @endforeach
    </div>

    {{-- DESKTOP: table --}}
    <div class="hidden sm:block border rounded overflow-auto">
      <table class="min-w-full text-sm" id="tbl">
        <thead class="bg-slate-50">
          <tr>
            <th class="text-left p-2">Jam Mulai</th>
            <th class="text-left p-2">Jam Selesai</th>
            <th class="text-left p-2">Nama Nasabah</th>
            <th class="text-left p-2">Account No</th>
            <th class="text-left p-2">Kolek</th>
            <th class="text-left p-2">Jenis</th>
            <th class="text-left p-2">Tujuan</th>
            <th class="text-left p-2">Area</th>
            <th class="text-left p-2">Catatan</th>
            <th class="text-left p-2">Aksi</th>
          </tr>
        </thead>
        <tbody id="tbody">
          @foreach($rows as $i => $it)
            @include('rkh.partials.edit-row', ['i' => $i, 'it' => $it, 'jenis' => $jenis])
          @endforeach
        </tbody>
      </table>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-3">
      <button class="px-4 py-2 rounded bg-black text-white">Simpan Perubahan</button>
      <button type="button" id="btnAddRow" class="px-3 py-2 rounded border">+ Tambah Baris</button>
    </div>
  </form>
</div>

{{-- DATA untuk dropdown tujuan (map) --}}
<script>
  window.__TUJUAN_BY_JENIS__ = @json($tujuanByJenis ?? []);
</script>

{{-- Templates --}}
<template id="rowTpl">
  @include('rkh.partials.edit-row', ['i' => '__INDEX__', 'it' => [], 'jenis' => $jenis])
</template>

<template id="cardTpl">
  @include('rkh.partials.edit-card', ['i' => '__INDEX__', 'it' => [], 'jenis' => $jenis])
</template>

<script>
document.addEventListener('DOMContentLoaded', () => {

  const tbody = document.getElementById('tbody');
  const cards = document.getElementById('cards');

  const tujuanMap = window.__TUJUAN_BY_JENIS__ || {};

  // normalize: trim, lowercase, ubah spasi/dash ke underscore, buang karakter aneh
  function normalizeKey(v){
    return String(v ?? '')
      .trim()
      .toLowerCase()
      .replace(/[\s\-]+/g, '_')         // spasi/dash -> underscore
      .replace(/[^a-z0-9_]/g, '');      // buang selain alnum + underscore
  }

  function pickListByKey(map, key){
    const k = normalizeKey(key);
    if (!k) return [];
    return map[k] || [];
  }

  function setTujuanOptions(scopeEl, jenisCode, currentValue = '') {
    const tujuanSelect = scopeEl.querySelector('select[data-tujuan]');
    if (!tujuanSelect) return;

    const key  = normalizeKey(jenisCode);
    const list = pickListByKey(tujuanMap, key);

    // 🔥 debug paling penting
    console.log('[TUJUAN] rawJenis=', jenisCode, 'normJenis=', key, 'list_len=', list.length, 'current=', currentValue);

    tujuanSelect.innerHTML = '';
    tujuanSelect.appendChild(new Option('Pilih', ''));

    list.forEach(it => {
      const code  = String(it.code ?? '');
      const label = String(it.label ?? code);
      const opt = new Option(label, code);
      if (currentValue && String(currentValue) === code) opt.selected = true;
      tujuanSelect.appendChild(opt);
    });

    // kalau currentValue tidak ada di list, tetap tampilkan (agar tidak "hilang" saat edit)
    const cur = String(currentValue ?? '');
    if (cur && !list.find(x => String(x.code ?? '') === cur)) {
      const opt = new Option(`${cur} (custom)`, cur);
      opt.selected = true;
      tujuanSelect.appendChild(opt);
    }

    // reset data-current supaya value aktual dipakai
    tujuanSelect.removeAttribute('data-current');
  }

  function toggleNetworking(scopeEl, jenisCode) {
    const netBox = scopeEl.querySelector('[data-networking-box]');
    if (!netBox) return;

    const k = normalizeKey(jenisCode);
    netBox.style.display = (k === 'pengembangan_jaringan') ? 'block' : 'none';
  }

  function wireScope(scopeEl) {
    const jenisSel  = scopeEl.querySelector('select[data-jenis]');
    const tujuanSel = scopeEl.querySelector('select[data-tujuan]');

    if (!jenisSel)  console.warn('[WIRE] jenisSel not found in scope', scopeEl);
    if (!tujuanSel) console.warn('[WIRE] tujuanSel not found in scope', scopeEl);
    if (!jenisSel || !tujuanSel) return;

    const jenisRaw = jenisSel.value || '';
    const tujuanRaw = tujuanSel.getAttribute('data-current') || tujuanSel.value || '';

    setTujuanOptions(scopeEl, jenisRaw, tujuanRaw);
    toggleNetworking(scopeEl, jenisRaw);

    // re-bind change (hindari double bind kalau wireScope kepanggil lagi)
    if (!jenisSel.dataset.wired) {
      jenisSel.dataset.wired = '1';
      jenisSel.addEventListener('change', function(){
        setTujuanOptions(scopeEl, this.value, '');
        toggleNetworking(scopeEl, this.value);
      });
    }
  }

  console.log('tujuanMap_keys_sample:', Object.keys(tujuanMap).slice(0, 20));
  console.log('tujuanMap_isEmpty:', Object.keys(tujuanMap).length === 0);

  (tbody?.querySelectorAll('tr[data-row]') || []).forEach(wireScope);
  (cards?.querySelectorAll('[data-card]') || []).forEach(wireScope);

});
</script>
@endsection
