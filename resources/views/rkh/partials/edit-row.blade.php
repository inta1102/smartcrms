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
@endphp

<tr class="border-t align-top" data-row="{{ is_numeric($i) ? (int)$i : 0 }}">
  <td class="p-2">
    <input type="time"
      name="items[{{ $i }}][jam_mulai]"
      value="{{ $val('jam_mulai') }}"
      class="border rounded px-2 py-1">
  </td>

  <td class="p-2">
    <input type="time"
      name="items[{{ $i }}][jam_selesai]"
      value="{{ $val('jam_selesai') }}"
      class="border rounded px-2 py-1">
  </td>

  <td class="p-2">
    <input type="text"
      name="items[{{ $i }}][nama_nasabah]"
      value="{{ $val('nama_nasabah') }}"
      class="border rounded px-2 py-1 w-56"
      placeholder="Nama debitur/nasabah">
    <input type="hidden" name="items[{{ $i }}][nasabah_id]" value="{{ $val('nasabah_id') }}">
  </td>

  <td class="p-2">
    <input type="text"
      name="items[{{ $i }}][account_no]"
      value="{{ $val('account_no') }}"
      class="w-40 border rounded p-2 text-sm"
      placeholder="Opsional (nasabah existing)"
      inputmode="numeric">
    <div class="text-[10px] text-slate-500 mt-1">Kosong = prospect</div>
  </td>

  <td class="p-2">
    <select name="items[{{ $i }}][kolektibilitas]" class="border rounded px-2 py-1">
      <option value="">-</option>
      <option value="L0" @selected($val('kolektibilitas')==='L0')>L0</option>
      <option value="LT" @selected($val('kolektibilitas')==='LT')>LT</option>
    </select>
  </td>

  {{-- JENIS + Networking box --}}
  <td class="p-2">
    <select name="items[{{ $i }}][jenis_kegiatan]"
        class="border rounded px-2 py-1 w-full min-w-[170px]"
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

    {{-- Networking ONLY ONE, valid HTML --}}
    <div class="mt-2 p-2 border rounded bg-slate-50" data-networking-box style="display:none;">
      <div class="text-xs font-semibold mb-1">Pengembangan Jaringan</div>

      <div class="grid grid-cols-2 gap-2">
        <div>
          <div class="text-xs text-slate-600">Nama Relasi (wajib)</div>
          <input type="text"
            name="items[{{ $i }}][networking][nama_relasi]"
            value="{{ $netVal('nama_relasi') }}"
            class="border rounded px-2 py-1 w-full"
            placeholder="Mis: Ketua Paguyuban Pasar">
        </div>

        <div>
          <div class="text-xs text-slate-600">Jenis Relasi</div>
          @php $jr = $netVal('jenis_relasi','lainnya'); @endphp
          <select name="items[{{ $i }}][networking][jenis_relasi]" class="border rounded px-2 py-1 w-full">
            <option value="supplier" @selected($jr==='supplier')>supplier</option>
            <option value="komunitas" @selected($jr==='komunitas')>komunitas</option>
            <option value="tokoh" @selected($jr==='tokoh')>tokoh</option>
            <option value="umkm" @selected($jr==='umkm')>umkm</option>
            <option value="lainnya" @selected($jr==='lainnya')>lainnya</option>
          </select>
        </div>

        <div class="col-span-2">
          <div class="text-xs text-slate-600">Potensi</div>
          <textarea name="items[{{ $i }}][networking][potensi]"
            class="border rounded px-2 py-1 w-full"
            rows="2">{{ $netVal('potensi') }}</textarea>
        </div>

        <div class="col-span-2">
          <div class="text-xs text-slate-600">Rencana Follow Up</div>
          <textarea name="items[{{ $i }}][networking][follow_up]"
            class="border rounded px-2 py-1 w-full"
            rows="2">{{ $netVal('follow_up') }}</textarea>
        </div>
      </div>
    </div>
  </td>

  {{-- TUJUAN --}}
  <td class="p-2">
    <select
      name="items[{{ $i }}][tujuan_kegiatan]"
      data-tujuan
      data-current="{{ $tujuanVal }}"
      class="w-56 border rounded p-2 text-sm">
      <option value="">Pilih</option>
    </select>
  </td>

  <td class="p-2">
    <input type="text"
      name="items[{{ $i }}][area]"
      value="{{ $val('area') }}"
      class="border rounded px-2 py-1 w-40"
      placeholder="Area/Cluster">
  </td>

  <td class="p-2">
    <input type="text"
      name="items[{{ $i }}][catatan]"
      value="{{ $val('catatan') }}"
      class="border rounded px-2 py-1 w-64"
      placeholder="Catatan singkat">
  </td>

  <td class="p-2">
    <button type="button" data-remove="1" class="px-2 py-1 border rounded">Hapus</button>
  </td>
</tr>
