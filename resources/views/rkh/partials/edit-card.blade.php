@php
  $i = $i ?? 0;
  $it = $it ?? [];
  $net = $it['networking'] ?? [];

  $val = function($key, $default='') use ($i, $it) {
    return old("items.$i.$key", $it[$key] ?? $default);
  };

  $netVal = function($key, $default='') use ($i, $net) {
    return old("items.$i.networking.$key", $net[$key] ?? $default);
  };

  $jenisVal  = (string) $val('jenis_kegiatan');
  $tujuanVal = (string) $val('tujuan_kegiatan');

  $no = is_numeric($i) ? ((int)$i + 1) : 1;
@endphp

<div class="border rounded-xl p-3 bg-white shadow-sm" data-card="{{ is_numeric($i) ? (int)$i : 0 }}">
  <div class="flex items-start justify-between gap-2">
    <div>
      <div class="text-xs text-slate-500" data-no>Kegiatan #{{ $no }}</div>
      <div class="text-sm font-semibold">
        <span data-preview-jam>
          {{ $val('jam_mulai') ?: '--:--' }} - {{ $val('jam_selesai') ?: '--:--' }}
        </span>
      </div>
    </div>

    <button type="button" data-remove="1" class="px-3 py-2 rounded-lg border text-sm">
      Hapus
    </button>
  </div>

  <div class="mt-3 grid grid-cols-2 gap-2">
    <div>
      <label class="block text-xs text-slate-600 mb-1">Jam Mulai</label>
      <input type="time"
        name="items[{{ $i }}][jam_mulai]"
        value="{{ $val('jam_mulai') }}"
        class="w-full border rounded-lg px-3 py-2"
        data-jam-mulai>
    </div>
    <div>
      <label class="block text-xs text-slate-600 mb-1">Jam Selesai</label>
      <input type="time"
        name="items[{{ $i }}][jam_selesai]"
        value="{{ $val('jam_selesai') }}"
        class="w-full border rounded-lg px-3 py-2"
        data-jam-selesai>
    </div>
  </div>

  <div class="mt-3">
    <label class="block text-xs text-slate-600 mb-1">Nama Nasabah</label>
    <input type="text"
      name="items[{{ $i }}][nama_nasabah]"
      value="{{ $val('nama_nasabah') }}"
      class="w-full border rounded-lg px-3 py-2"
      placeholder="Nama debitur/nasabah">
    <input type="hidden" name="items[{{ $i }}][nasabah_id]" value="{{ $val('nasabah_id') }}">
  </div>

  <div class="mt-2">
    <label class="text-xs font-semibold text-slate-600">Account No (opsional)</label>
    <input type="text"
      name="items[{{ $i }}][account_no]"
      value="{{ $val('account_no') }}"
      class="w-full mt-1 border rounded p-2 text-sm"
      placeholder="Isi jika sudah punya rekening"
      inputmode="numeric">
    <div class="text-[10px] text-slate-500 mt-1">
      Kosong = prospect (belum ada rekening)
    </div>
  </div>

  <div class="mt-3 grid grid-cols-2 gap-2">
    <div>
      <label class="block text-xs text-slate-600 mb-1">Kolek</label>
      <select name="items[{{ $i }}][kolektibilitas]" class="w-full border rounded-lg px-3 py-2">
        <option value="">-</option>
        <option value="L0" @selected($val('kolektibilitas')==='L0')>L0</option>
        <option value="LT" @selected($val('kolektibilitas')==='LT')>LT</option>
      </select>
    </div>

    <div>
      <label class="block text-xs text-slate-600 mb-1">Area</label>
      <input type="text"
        name="items[{{ $i }}][area]"
        value="{{ $val('area') }}"
        class="w-full border rounded-lg px-3 py-2"
        placeholder="Area/Cluster">
    </div>
  </div>

  <div class="mt-3">
    <label class="block text-xs text-slate-600 mb-1">Jenis Kegiatan</label>
    <select name="items[{{ $i }}][jenis_kegiatan]"
      class="w-full border rounded-lg px-3 py-2"
      data-jenis>
      <option value="">Pilih</option>

      @foreach($jenis as $j)
        @php
          $code  = is_array($j) ? (string)($j['code'] ?? '') : (string)($j->code ?? '');
          $label = is_array($j) ? (string)($j['label'] ?? $code) : (string)($j->label ?? $code);
        @endphp
        <option value="{{ $code }}" @selected((string)$jenisVal === (string)$code)>{{ $label }}</option>
      @endforeach
    </select>
  </div>

  <div class="mt-3">
    <label class="block text-xs text-slate-600 mb-1">Tujuan Kegiatan</label>
    <select
      name="items[{{ $i }}][tujuan_kegiatan]"
      data-tujuan
      data-current="{{ $tujuanVal }}"
      class="w-full mt-1 border rounded p-2 text-sm">
      <option value="">Pilih</option>
    </select>

    <div class="text-[11px] text-slate-500 mt-1">Tujuan otomatis mengikuti Jenis.</div>
  </div>

  <div class="mt-3">
    <label class="block text-xs text-slate-600 mb-1">Catatan</label>
    <textarea name="items[{{ $i }}][catatan]"
      class="w-full border rounded-lg px-3 py-2"
      rows="2"
      placeholder="Catatan singkat">{{ $val('catatan') }}</textarea>
  </div>

  <div class="mt-3" data-networking-box style="display:none;">
    <div class="p-3 border rounded-xl bg-slate-50">
      <div class="text-xs font-semibold mb-2">Pengembangan Jaringan</div>

      <div class="grid grid-cols-1 gap-2">
        <div>
          <label class="block text-xs text-slate-600 mb-1">Nama Relasi (wajib)</label>
          <input type="text"
            name="items[{{ $i }}][networking][nama_relasi]"
            value="{{ $netVal('nama_relasi') }}"
            class="w-full border rounded-lg px-3 py-2"
            placeholder="Mis: Ketua Paguyuban Pasar">
        </div>

        <div>
          <label class="block text-xs text-slate-600 mb-1">Jenis Relasi</label>
          @php $jr = $netVal('jenis_relasi','lainnya'); @endphp
          <select name="items[{{ $i }}][networking][jenis_relasi]" class="w-full border rounded-lg px-3 py-2">
            <option value="supplier" @selected($jr==='supplier')>supplier</option>
            <option value="komunitas" @selected($jr==='komunitas')>komunitas</option>
            <option value="tokoh" @selected($jr==='tokoh')>tokoh</option>
            <option value="umkm" @selected($jr==='umkm')>umkm</option>
            <option value="lainnya" @selected($jr==='lainnya')>lainnya</option>
          </select>
        </div>

        <div>
          <label class="block text-xs text-slate-600 mb-1">Potensi</label>
          <textarea name="items[{{ $i }}][networking][potensi]"
            class="w-full border rounded-lg px-3 py-2"
            rows="2">{{ $netVal('potensi') }}</textarea>
        </div>

        <div>
          <label class="block text-xs text-slate-600 mb-1">Follow Up</label>
          <textarea name="items[{{ $i }}][networking][follow_up]"
            class="w-full border rounded-lg px-3 py-2"
            rows="2">{{ $netVal('follow_up') }}</textarea>
        </div>
      </div>
    </div>
  </div>
</div>
