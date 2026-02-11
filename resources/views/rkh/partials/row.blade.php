@php
  $i = $i ?? 0;
  $it = $it ?? [];
  $v = fn($k, $d='') => old("items.$i.$k", $it[$k] ?? $d);
@endphp

<tr class="border-t" data-row="1">
  <td class="p-2">
    <input type="time" name="items[{{ $i }}][jam_mulai]" value="{{ $v('jam_mulai') }}" class="border rounded px-2 py-1">
  </td>
  <td class="p-2">
    <input type="time" name="items[{{ $i }}][jam_selesai]" value="{{ $v('jam_selesai') }}" class="border rounded px-2 py-1">
  </td>

  <td class="p-2">
    <input type="text" name="items[{{ $i }}][nama_nasabah]" value="{{ $v('nama_nasabah') }}"
      class="border rounded px-2 py-1 w-56" placeholder="Nama debitur/nasabah">
    <input type="hidden" name="items[{{ $i }}][nasabah_id]" value="{{ $v('nasabah_id') }}">
  </td>

  <td class="p-2">
    <select name="items[{{ $i }}][kolektibilitas]" class="border rounded px-2 py-1">
      <option value="">-</option>
      <option value="L0" @selected($v('kolektibilitas')==='L0')>L0</option>
      <option value="LT" @selected($v('kolektibilitas')==='LT')>LT</option>
    </select>
  </td>

  <td class="p-2">
    <select name="items[{{ $i }}][jenis_kegiatan]" class="border rounded px-2 py-1">
      <option value="">Pilih</option>
      @foreach($jenis as $j)
        <option value="{{ $j->code }}" @selected($v('jenis_kegiatan')===$j->code)>{{ $j->label }}</option>
      @endforeach
    </select>
  </td>

  <td class="p-2">
    {{-- tujuan diisi manual dulu (simple). Next step bisa dynamic ajax by jenis --}}
    <input type="text" name="items[{{ $i }}][tujuan_kegiatan]" value="{{ $v('tujuan_kegiatan') }}"
      class="border rounded px-2 py-1 w-56" placeholder="contoh: reminder_angs_akan_jt">
    <div class="text-xs text-slate-500 mt-1">Kode tujuan harus valid sesuai master.</div>
  </td>

  <td class="p-2">
    <input type="text" name="items[{{ $i }}][area]" value="{{ $v('area') }}" class="border rounded px-2 py-1 w-40" placeholder="Area/Cluster">
  </td>

  <td class="p-2">
    <input type="text" name="items[{{ $i }}][catatan]" value="{{ $v('catatan') }}" class="border rounded px-2 py-1 w-64" placeholder="Catatan singkat">
  </td>

  <td class="p-2">
    <button type="button" data-remove="1" class="px-2 py-1 border rounded">Hapus</button>
  </td>
</tr>
