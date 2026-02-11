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
(function(){
  const tbody = document.getElementById('tbody');   // desktop
  const cards = document.getElementById('cards');   // mobile

  const rowTpl  = document.getElementById('rowTpl')?.innerHTML || '';
  const cardTpl = document.getElementById('cardTpl')?.innerHTML || '';

  const btnAddRow = document.getElementById('btnAddRow');
  const tujuanMap = window.__TUJUAN_BY_JENIS__ || {};

  function countRows(){
    const n1 = tbody ? tbody.querySelectorAll('tr[data-row]').length : 0;
    const n2 = cards ? cards.querySelectorAll('[data-card]').length : 0;
    return Math.max(n1, n2);
  }

  function setTujuanOptions(scopeEl, jenisCode, currentValue = '') {
    const tujuanSelect = scopeEl.querySelector('select[data-tujuan]');
    if (!tujuanSelect) return;

    const list = tujuanMap[jenisCode] || [];

    tujuanSelect.innerHTML = '';
    const opt0 = document.createElement('option');
    opt0.value = '';
    opt0.textContent = 'Pilih';
    tujuanSelect.appendChild(opt0);

    list.forEach(it => {
      const o = document.createElement('option');
      o.value = it.code;
      o.textContent = it.label;
      if (currentValue && currentValue === it.code) o.selected = true;
      tujuanSelect.appendChild(o);
    });

    if (currentValue && !list.find(x => x.code === currentValue)) {
      const o = document.createElement('option');
      o.value = currentValue;
      o.textContent = currentValue + ' (custom)';
      o.selected = true;
      tujuanSelect.appendChild(o);
    }
  }

  function toggleNetworking(scopeEl, jenisCode) {
    const netBox = scopeEl.querySelector('[data-networking-box]');
    if (!netBox) return;
    netBox.style.display = (jenisCode === 'pengembangan_jaringan') ? 'block' : 'none';
  }

  function wireScope(scopeEl) {
    const jenisSel  = scopeEl.querySelector('select[data-jenis]');
    const tujuanSel = scopeEl.querySelector('select[data-tujuan]');

    const jenisCode = (jenisSel?.value || '');
    const tujuanVal = (tujuanSel?.getAttribute('data-current') || tujuanSel?.value || '');

    setTujuanOptions(scopeEl, jenisCode, tujuanVal);
    toggleNetworking(scopeEl, jenisCode);

    jenisSel?.addEventListener('change', function(){
      setTujuanOptions(scopeEl, this.value, '');
      toggleNetworking(scopeEl, this.value);
    });

    // mobile jam preview
    const jm = scopeEl.querySelector('[data-jam-mulai]');
    const js = scopeEl.querySelector('[data-jam-selesai]');
    const prev = scopeEl.querySelector('[data-preview-jam]');
    if (jm && js && prev) {
      const refresh = () => prev.textContent = `${jm.value || '--:--'} - ${js.value || '--:--'}`;
      jm.addEventListener('change', refresh);
      js.addEventListener('change', refresh);
      refresh();
    }
  }

  function appendHtml(container, html) {
    if (!container || !html) return null;
    const temp = document.createElement('div');
    temp.innerHTML = html.trim();
    const el = temp.firstElementChild;
    container.appendChild(el);
    return el;
  }

  function addRow(prefill = {}) {
    const idx = countRows();

    const rowEl  = appendHtml(tbody, rowTpl.replaceAll('__INDEX__', idx));
    const cardEl = appendHtml(cards, cardTpl.replaceAll('__INDEX__', idx));

    function fill(scopeEl) {
      if (!scopeEl) return;

      if (prefill.jam_mulai) scopeEl.querySelector(`[name="items[${idx}][jam_mulai]"]`)?.value = prefill.jam_mulai;
      if (prefill.jam_selesai) scopeEl.querySelector(`[name="items[${idx}][jam_selesai]"]`)?.value = prefill.jam_selesai;

      if (prefill.nama_nasabah) scopeEl.querySelector(`[name="items[${idx}][nama_nasabah]"]`)?.value = prefill.nama_nasabah;
      if (prefill.kolektibilitas) scopeEl.querySelector(`[name="items[${idx}][kolektibilitas]"]`)?.value = prefill.kolektibilitas;
      if (prefill.area) scopeEl.querySelector(`[name="items[${idx}][area]"]`)?.value = prefill.area;
      if (prefill.catatan) scopeEl.querySelector(`[name="items[${idx}][catatan]"]`)?.value = prefill.catatan;

      if (prefill.jenis_kegiatan) scopeEl.querySelector(`[name="items[${idx}][jenis_kegiatan]"]`)?.value = prefill.jenis_kegiatan;

      const tujuanSel = scopeEl.querySelector('select[data-tujuan]');
      if (tujuanSel && prefill.tujuan_kegiatan) {
        tujuanSel.setAttribute('data-current', prefill.tujuan_kegiatan);
      }

      wireScope(scopeEl);
    }

    fill(rowEl);
    fill(cardEl);
  }

  // init existing scopes
  (tbody?.querySelectorAll('tr[data-row]') || []).forEach(wireScope);
  (cards?.querySelectorAll('[data-card]') || []).forEach(wireScope);

  btnAddRow?.addEventListener('click', () => addRow());

  function removeAtIndex(idx) {
    tbody?.querySelectorAll('tr[data-row]')[idx]?.remove();
    cards?.querySelectorAll('[data-card]')[idx]?.remove();
  }

    function refreshCardNumbers(){
    (cards?.querySelectorAll('[data-card]') || []).forEach((card, idx) => {
        const el = card.querySelector('[data-no]');
        if (el) el.textContent = `Kegiatan #${idx+1}`;
    });
    }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-remove]');
    if (!btn) return;

    const row = btn.closest('tr[data-row]');
    if (row && tbody) {
      const list = Array.from(tbody.querySelectorAll('tr[data-row]'));
      const idx = list.indexOf(row);
      if (idx >= 0) removeAtIndex(idx);
      return;
    }

    const card = btn.closest('[data-card]');
    if (card && cards) {
      const list = Array.from(cards.querySelectorAll('[data-card]'));
      const idx = list.indexOf(card);
      if (idx >= 0) removeAtIndex(idx);
      return;
    }
  });
})();
</script>
@endsection
