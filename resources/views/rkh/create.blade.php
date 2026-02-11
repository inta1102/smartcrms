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

    @if(!empty($smartReminder))
      <div class="mt-4 border rounded-xl p-3 bg-white">
        <div class="flex items-start justify-between gap-2">
          <div>
            <div class="text-sm font-bold">Nasabah Wajib Dikunjungi</div>
            <div class="text-xs text-slate-500">Smart reminder otomatis (read-only). Klik untuk masukkan ke RKH.</div>
          </div>
          <button type="button" class="px-3 py-2 border rounded-lg text-sm" id="btnSmartToggle">Tutup</button>
        </div>

        <div id="smartBox" class="mt-3">
          {{-- mobile cards --}}
          <div class="sm:hidden space-y-2">
            @foreach($smartReminder as $row)
              <div class="border rounded-xl p-3">
                <div class="font-semibold text-sm">{{ $row['nama'] }}</div>
                <div class="text-xs text-slate-500">
                  {{ $row['account_no'] }} • CIF {{ $row['cif'] }}
                </div>

                <div class="mt-2 text-xs text-slate-600">
                  Kolek: <b>{{ $row['kolek_last'] }}</b> → <b>{{ $row['kolek_now'] }}</b>
                  • DPD: <b>{{ $row['dpd'] }}</b>
                  • Plafon: <b>{{ number_format($row['plafon']) }}</b>
                </div>

                <div class="mt-2 flex flex-wrap gap-2 text-xs">
                  @foreach(($row['reasons'] ?? []) as $r)
                    <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-800">{{ $r }}</span>
                  @endforeach
                </div>

                <div class="mt-3">
                  <button type="button"
                    class="w-full px-3 py-2 rounded-lg bg-slate-900 text-white text-sm"
                    data-smart-add='@json($row)'>
                    + Masukkan ke RKH
                  </button>
                </div>
              </div>
            @endforeach
          </div>

          {{-- desktop table --}}
          <div class="hidden sm:block overflow-auto">
            <table class="min-w-full text-sm">
              <thead class="bg-slate-50">
                <tr>
                  <th class="text-left p-2">Nama</th>
                  <th class="text-left p-2">Kolek</th>
                  <th class="text-left p-2">DPD</th>
                  <th class="text-left p-2">Plafon</th>
                  <th class="text-left p-2">Alasan</th>
                  <th class="text-left p-2">Aksi</th>
                </tr>
              </thead>
              <tbody>
                @foreach($smartReminder as $row)
                  <tr class="border-t">
                    <td class="p-2">
                      <div class="font-medium">{{ $row['nama'] }}</div>
                      <div class="text-xs text-slate-500">{{ $row['account_no'] }} • CIF {{ $row['cif'] }}</div>
                    </td>
                    <td class="p-2">{{ $row['kolek_last'] }} → {{ $row['kolek_now'] }}</td>
                    <td class="p-2">{{ $row['dpd'] }}</td>
                    <td class="p-2">{{ number_format($row['plafon']) }}</td>
                    <td class="p-2">
                      <div class="flex flex-wrap gap-1">
                        @foreach(($row['reasons'] ?? []) as $r)
                          <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-800 text-xs">{{ $r }}</span>
                        @endforeach
                      </div>
                    </td>
                    <td class="p-2">
                      <button type="button"
                        class="px-3 py-2 rounded-lg border text-sm"
                        data-smart-add='@json($row)'>
                        Masukkan RKH
                      </button>
                    </td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>

        </div>
      </div>
    @endif

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

    @php $oldItems = old('items'); @endphp

    {{-- MOBILE (cards) --}}
    <div class="mt-4 sm:hidden space-y-3" id="cards">
      @if(is_array($oldItems))
        @foreach($oldItems as $i => $it)
          @include('rkh.partials.edit-card', ['i' => $i, 'it' => $it, 'jenis' => $jenis])
        @endforeach
      @endif
    </div>

    {{-- DESKTOP (table) --}}
    <div class="mt-4 border rounded overflow-auto hidden sm:block">
      <table class="w-full text-sm table-fixed" id="tbl">
        <thead class="bg-slate-50">
          <tr>
            <th class="text-left p-2 w-[120px]">Jam Mulai</th>
            <th class="text-left p-2 w-[120px]">Jam Selesai</th>
            <th class="text-left p-2 w-[260px]">Nama Nasabah</th>
            <th class="text-left p-2 w-[90px]">Kolek</th>
            <th class="text-left p-2 w-[170px]">Jenis</th>
            <th class="text-left p-2 w-[260px]">Tujuan</th>
            <th class="text-left p-2 w-[140px]">Area</th>
            <th class="text-left p-2 w-[280px]">Catatan</th>
            <th class="text-left p-2 w-[90px]">Aksi</th>
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

{{-- templates --}}
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
    const n1 = tbody ? tbody.querySelectorAll('tr[data-row]').length : 0;
    const n2 = cards ? cards.querySelectorAll('[data-card]').length : 0;
    return Math.max(n1, n2);
  }

  // ✅ penting: parse TR pakai TABLE wrapper biar aman
  function appendHtml(container, html) {
    if (!container || !html) return null;

    if (container.tagName === 'TBODY') {
      const temp = document.createElement('table');
      temp.innerHTML = `<tbody>${html.trim()}</tbody>`;
      const el = temp.querySelector('tbody')?.firstElementChild || null;
      if (el) container.appendChild(el);
      return el;
    }

    const temp = document.createElement('div');
    temp.innerHTML = html.trim();
    const el = temp.firstElementChild;
    if (el) container.appendChild(el);
    return el;
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
      tujuanSelect.appendChild(o);
    });

    // ✅ hanya set kalau currentValue valid. kalau tidak valid -> kosong
    if (currentValue && list.find(x => x.code === currentValue)) {
      tujuanSelect.value = currentValue;
    } else {
      tujuanSelect.value = '';
      tujuanSelect.removeAttribute('data-current');
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

    // penting: jangan dobel listener berkali-kali
    if (jenisSel && !jenisSel.__wired) {
      jenisSel.__wired = true;
      jenisSel.addEventListener('change', function(){
        setTujuanOptions(scopeEl, this.value, '');
        toggleNetworking(scopeEl, this.value);
      });
    }

    // mobile preview jam (kalau element ada)
    const jm = scopeEl.querySelector('[data-jam-mulai]');
    const js = scopeEl.querySelector('[data-jam-selesai]');
    const prev = scopeEl.querySelector('[data-preview-jam]');
    if (jm && js && prev && !prev.__wired) {
      prev.__wired = true;
      const refresh = () => prev.textContent = `${jm.value || '--:--'} - ${js.value || '--:--'}`;
      jm.addEventListener('change', refresh);
      js.addEventListener('change', refresh);
      refresh();
    }
  }

  function refreshCardNumbers(){
    (cards?.querySelectorAll('[data-card]') || []).forEach((card, idx) => {
      const el = card.querySelector('[data-no]');
      if (el) el.textContent = `Kegiatan #${idx+1}`;
    });
  }

  function setVal(scopeEl, selector, value) {
    const el = scopeEl?.querySelector(selector);
    if (!el) return;
    el.value = (value ?? '');
  }

  function fillScopeWithPrefill(scopeEl, idx, prefill = {}) {
    if (!scopeEl) return;

    // jam
    if (prefill.jam_mulai !== undefined)   setVal(scopeEl, `[name="items[${idx}][jam_mulai]"]`, prefill.jam_mulai);
    if (prefill.jam_selesai !== undefined) setVal(scopeEl, `[name="items[${idx}][jam_selesai]"]`, prefill.jam_selesai);

    // nasabah
    if (prefill.nama_nasabah !== undefined) setVal(scopeEl, `[name="items[${idx}][nama_nasabah]"]`, prefill.nama_nasabah);
    if (prefill.nasabah_id !== undefined)   setVal(scopeEl, `[name="items[${idx}][nasabah_id]"]`, prefill.nasabah_id);

    // lainnya
    if (prefill.kolektibilitas !== undefined) setVal(scopeEl, `[name="items[${idx}][kolektibilitas]"]`, prefill.kolektibilitas);
    if (prefill.area !== undefined)          setVal(scopeEl, `[name="items[${idx}][area]"]`, prefill.area);
    if (prefill.catatan !== undefined)       setVal(scopeEl, `[name="items[${idx}][catatan]"]`, prefill.catatan);

    // jenis + tujuan (tujuan diset via data-current supaya wireScope isi option)
    if (prefill.jenis_kegiatan !== undefined) {
      setVal(scopeEl, `[name="items[${idx}][jenis_kegiatan]"]`, prefill.jenis_kegiatan);
    }

    if (prefill.tujuan_kegiatan !== undefined) {
      const tujuanSel = scopeEl.querySelector('select[data-tujuan]');
      if (tujuanSel) tujuanSel.setAttribute('data-current', prefill.tujuan_kegiatan || '');
    }

    wireScope(scopeEl);
  }

  function addRow(prefill = {}) {
    const idx = countRows();

    const rowEl  = appendHtml(tbody, rowTpl.replaceAll('__INDEX__', idx));
    const cardEl = appendHtml(cards, cardTpl.replaceAll('__INDEX__', idx));

    fillScopeWithPrefill(rowEl, idx, prefill);
    fillScopeWithPrefill(cardEl, idx, prefill);

    refreshCardNumbers();
    return idx;
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

  // ====== MODE: isi slot kosong dulu ======
  function isEmptyRowByIndex(idx) {
    // cek desktop dulu
    const row = tbody?.querySelectorAll('tr[data-row]')?.[idx] || null;
    if (row) {
      const nm = row.querySelector(`[name="items[${idx}][nama_nasabah]"]`);
      if (nm) return (nm.value || '').trim() === '';
    }

    // fallback cek card (mobile)
    const card = cards?.querySelectorAll('[data-card]')?.[idx] || null;
    if (card) {
      const nm = card.querySelector(`[name="items[${idx}][nama_nasabah]"]`);
      if (nm) return (nm.value || '').trim() === '';
    }

    return false;
  }

  function fillExistingIndex(idx, prefill = {}) {
    const row  = tbody?.querySelectorAll('tr[data-row]')?.[idx] || null;
    const card = cards?.querySelectorAll('[data-card]')?.[idx] || null;

    fillScopeWithPrefill(row, idx, prefill);
    fillScopeWithPrefill(card, idx, prefill);

    refreshCardNumbers();
  }

  function fillFirstEmptyOrAdd(prefill = {}) {
    const n = countRows();
    for (let i = 0; i < n; i++) {
      if (isEmptyRowByIndex(i)) {
        fillExistingIndex(i, prefill);
        return { usedIndex: i, created: false };
      }
    }
    const newIdx = addRow(prefill);
    return { usedIndex: newIdx, created: true };
  }

  // toggle panel
  const btnSmartToggle = document.getElementById('btnSmartToggle');
  const smartBox = document.getElementById('smartBox');
  btnSmartToggle?.addEventListener('click', () => {
    if (!smartBox) return;
    const hidden = smartBox.classList.toggle('hidden');
    btnSmartToggle.textContent = hidden ? 'Buka' : 'Tutup';
  });

  // klik masukkan ke RKH (DELEGATED)
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-smart-add]');
    if (!btn) return;

    let data = {};
    try { data = JSON.parse(btn.getAttribute('data-smart-add') || '{}'); } catch (err) { data = {}; }

    const sug = data.suggest || {};

    // ✅ isi slot kosong dulu. kalau penuh -> tambah baris
    const prefill = {
      nama_nasabah: data.nama || '',
      nasabah_id: data.nasabah_id || '',
      kolektibilitas: sug.kolektibilitas || data.kolek_now || '',

      // IMPORTANT: ini harus CODE master_jenis_kegiatan / master_tujuan_kegiatan
      jenis_kegiatan: sug.jenis_kegiatan || '',
      tujuan_kegiatan: sug.tujuan_kegiatan || '',

      catatan: sug.catatan || (data.reasons ? `SmartReminder: ${data.reasons.join('; ')}` : ''),
    };

    const res = fillFirstEmptyOrAdd(prefill);

    // disable biar tidak dobel spam klik
    btn.disabled = true;
    btn.textContent = '✓ Sudah dimasukkan';
  });

  // init existing
  (tbody?.querySelectorAll('tr[data-row]') || []).forEach(wireScope);
  (cards?.querySelectorAll('[data-card]') || []).forEach(wireScope);
  refreshCardNumbers();

  // auto generate kalau kosong
  if (countRows() === 0) genDefault4();

  btnAddRow?.addEventListener('click', () => addRow());
  btnGenerate?.addEventListener('click', genDefault4);

  function removeAtIndex(idx) {
    tbody?.querySelectorAll('tr[data-row]')[idx]?.remove();
    cards?.querySelectorAll('[data-card]')[idx]?.remove();
    refreshCardNumbers();
  }

  // delegated remove
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
