@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">

  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold">Buat RKH</h1>
      <p class="text-sm text-slate-500">1 klik tanggal → langsung isi 4 kegiatan.</p>
    </div>
    <a href="{{ route('rkh.index') }}" class="px-3 py-2 rounded border">Kembali</a>
  </div>

  @if ($errors->any())
    <div class="mt-4 p-3 border rounded bg-red-50 text-red-700 text-sm">
      <div class="font-semibold mb-1">Ada error:</div>
      <ul class="list-disc ml-5">
        @foreach ($errors->all() as $e) <li>{{ $e }}</li> @endforeach
      </ul>
    </div>
  @endif

  <form class="mt-4" method="POST" action="{{ route('rkh.store') }}" id="rkhForm">
    @csrf

    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="block text-sm font-semibold">Tanggal</label>
        <input type="date" name="tanggal" id="tanggal"
          value="{{ old('tanggal') }}"
          class="border rounded px-3 py-2">
      </div>

      <button type="button" id="btnGenerate" class="px-3 py-2 rounded border">
        + Generate 4 Kegiatan
      </button>

      <button type="button" id="btnAddRow" class="px-3 py-2 rounded border">
        + Tambah Baris
      </button>
    </div>

    {{-- MOBILE (cards) --}}
    <div class="mt-4 sm:hidden space-y-3" id="cards">
    @php $oldItems = old('items'); @endphp
    @if(is_array($oldItems))
        @foreach($oldItems as $i => $it)
        @include('rkh.partials.edit-card', ['i' => $i, 'it' => $it, 'jenis' => $jenis])
        @endforeach
    @endif
    </div>

    {{-- DESKTOP (table) --}}
    <div class="mt-4 border rounded overflow-auto hidden sm:block">
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
        @if(is_array($oldItems))
            @foreach($oldItems as $i => $it)
            @include('rkh.partials.edit-row', ['i' => $i, 'it' => $it, 'jenis' => $jenis])
            @endforeach
        @endif
        </tbody>
    </table>
    </div>


    <div class="mt-4 flex items-center gap-3">
      <button class="px-4 py-2 rounded bg-black text-white">Simpan</button>
      <div class="text-xs text-slate-500">
        Jam tidak boleh overlap. Tujuan otomatis mengikuti Jenis.
      </div>
    </div>
  </form>
</div>

{{-- Data map tujuan --}}
<script>
  window.__TUJUAN_BY_JENIS__ = @json($tujuanByJenis ?? []);
</script>

{{-- template row (hidden) --}}
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

  const btnAddRow   = document.getElementById('btnAddRow');
  const btnGenerate = document.getElementById('btnGenerate');

  const tujuanMap = window.__TUJUAN_BY_JENIS__ || {};

  function countRows(){
    // hitung dari desktop kalau ada, kalau tidak dari card
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
    const jenisSel = scopeEl.querySelector('select[data-jenis]');
    const tujuanSel = scopeEl.querySelector('select[data-tujuan]');

    const jenisCode = (jenisSel?.value || '');
    const tujuanVal = (tujuanSel?.getAttribute('data-current') || tujuanSel?.value || '');

    setTujuanOptions(scopeEl, jenisCode, tujuanVal);
    toggleNetworking(scopeEl, jenisCode);

    jenisSel?.addEventListener('change', function(){
      setTujuanOptions(scopeEl, this.value, '');
      toggleNetworking(scopeEl, this.value);
    });

    // mobile preview jam
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

    // desktop row
    const rowEl = appendHtml(tbody, rowTpl.replaceAll('__INDEX__', idx));
    // mobile card
    const cardEl = appendHtml(cards, cardTpl.replaceAll('__INDEX__', idx));

    // prefill helper
    function fill(scopeEl) {
      if (!scopeEl) return;

      if (prefill.jam_mulai) scopeEl.querySelector(`[name="items[${idx}][jam_mulai]"]`)?.value = prefill.jam_mulai;
      if (prefill.jam_selesai) scopeEl.querySelector(`[name="items[${idx}][jam_selesai]"]`)?.value = prefill.jam_selesai;

      if (prefill.kolektibilitas) scopeEl.querySelector(`[name="items[${idx}][kolektibilitas]"]`)?.value = prefill.kolektibilitas;
      if (prefill.area) scopeEl.querySelector(`[name="items[${idx}][area]"]`)?.value = prefill.area;
      if (prefill.catatan) scopeEl.querySelector(`[name="items[${idx}][catatan]"]`)?.value = prefill.catatan;

      if (prefill.jenis_kegiatan) scopeEl.querySelector(`[name="items[${idx}][jenis_kegiatan]"]`)?.value = prefill.jenis_kegiatan;

      if (prefill.tujuan_kegiatan) {
        const tujuanSel = scopeEl.querySelector('select[data-tujuan]');
        if (tujuanSel) tujuanSel.setAttribute('data-current', prefill.tujuan_kegiatan);
      }

      wireScope(scopeEl);
    }

    fill(rowEl);
    fill(cardEl);
  }

  function clearAll() {
    if (tbody) tbody.innerHTML = '';
    if (cards) cards.innerHTML = '';
  }

  function genDefault4() {
    const has = countRows() > 0;
    if (has) {
      const ok = confirm('Data sudah ada. Generate 4 kegiatan akan menghapus semua baris. Lanjut?');
      if (!ok) return;
    }
    clearAll();
    addRow({ jam_mulai:'08:00', jam_selesai:'10:00' });
    addRow({ jam_mulai:'10:30', jam_selesai:'12:00' });
    addRow({ jam_mulai:'13:00', jam_selesai:'15:00' });
    addRow({ jam_mulai:'15:15', jam_selesai:'16:30' });
  }

  // init existing from server-rendered
  (tbody?.querySelectorAll('tr[data-row]') || []).forEach(wireScope);
  (cards?.querySelectorAll('[data-card]') || []).forEach(wireScope);

  // auto generate kalau kosong
  if (countRows() === 0) genDefault4();

  btnAddRow?.addEventListener('click', () => addRow());
  btnGenerate?.addEventListener('click', genDefault4);

  // remove handler both containers
  function removeAtIndex(idx) {
    // remove desktop row
    tbody?.querySelectorAll('tr[data-row]')[idx]?.remove();
    // remove mobile card
    cards?.querySelectorAll('[data-card]')[idx]?.remove();
  }

    function refreshCardNumbers(){
    (cards?.querySelectorAll('[data-card]') || []).forEach((card, idx) => {
        const el = card.querySelector('[data-no]');
        if (el) el.textContent = `Kegiatan #${idx+1}`;
    });
    }

  // delegated remove
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-remove]');
    if (!btn) return;

    // cari index di container masing-masing
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
