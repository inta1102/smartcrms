@csrf
@if(isset($method) && $method === 'PUT')
  @method('PUT')
@endif

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
  <div>
    <label class="text-sm font-semibold">Jenis Tindakan</label>
    <select name="action_type" class="w-full border rounded p-2 mt-1" required>
      <option value="">-- pilih --</option>
      @foreach($types as $k => $label)
        <option value="{{ $k }}" @selected(old('action_type', $nonLit->action_type ?? '') == $k)>{{ $label }}</option>
      @endforeach
    </select>
    @error('action_type') <div class="text-red-600 text-xs mt-1">{{ $message }}</div> @enderror
  </div>

  <div>
    <label class="text-sm font-semibold">Komitmen Nominal (opsional)</label>
    <input type="number" step="0.01" name="commitment_amount"
           value="{{ old('commitment_amount', $nonLit->commitment_amount ?? '') }}"
           class="w-full border rounded p-2 mt-1">
    @error('commitment_amount') <div class="text-red-600 text-xs mt-1">{{ $message }}</div> @enderror
  </div>

  <div class="md:col-span-2">
    <label class="text-sm font-semibold">Ringkasan Usulan</label>
    <input type="text" name="proposal_summary"
           value="{{ old('proposal_summary', $nonLit->proposal_summary ?? '') }}"
           class="w-full border rounded p-2 mt-1" required>
    @error('proposal_summary') <div class="text-red-600 text-xs mt-1">{{ $message }}</div> @enderror
  </div>

  <div class="md:col-span-2">
    <label class="text-sm font-semibold">Detail Usulan (JSON / catatan)</label>
    <textarea name="proposal_detail" rows="5"
      class="w-full border rounded p-2 mt-1"
      placeholder='Contoh: {"skema":"reschedule","tenor_baru":24,"catatan":"..." }'>{{ old('proposal_detail', isset($nonLit) ? (is_array($nonLit->proposal_detail) ? json_encode($nonLit->proposal_detail) : $nonLit->proposal_detail) : '') }}</textarea>
    @error('proposal_detail') <div class="text-red-600 text-xs mt-1">{{ $message }}</div> @enderror
  </div>

  <div>
    <label class="text-sm font-semibold">Effective Date (opsional)</label>
    <input type="date" name="effective_date"
           value="{{ old('effective_date', $nonLit->effective_date ?? '') }}"
           class="w-full border rounded p-2 mt-1">
  </div>

  <div>
    <label class="text-sm font-semibold">Monitoring Next Due (opsional)</label>
    <input type="date" name="monitoring_next_due"
           value="{{ old('monitoring_next_due', $nonLit->monitoring_next_due ?? '') }}"
           class="w-full border rounded p-2 mt-1">
  </div>
</div>
