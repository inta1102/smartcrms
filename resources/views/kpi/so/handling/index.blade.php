@extends('layouts.app')

@section('content')
<div class="max-w-5xl mx-auto p-4">
  <div class="mb-4">
    <h1 class="text-2xl font-bold text-slate-900">Realisasi Handling Komunitas (SO)</h1>
    <p class="text-sm text-slate-500">Diinput oleh TL/Kasi/Kabag. Setelah simpan, jalankan Recalc SO agar skor/pct ter-update.</p>
  </div>

  @if($errors->any())
    <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800 text-sm">
      <ul class="list-disc ml-5">
        @foreach($errors->all() as $e)
          <li>{{ $e }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  @if(session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm">
      {{ session('status') }}
    </div>
  @endif

  <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <form method="GET" action="{{ route('kpi.so.handling.index') }}" class="flex flex-wrap items-end gap-3">
      <div>
        <label class="text-sm font-semibold text-slate-700">Periode</label>
        <input type="month" name="period_month"
          value="{{ \Carbon\Carbon::parse($period)->format('Y-m') }}"
          class="mt-1 w-56 rounded-xl border border-slate-200 px-3 py-2 text-sm">
        <input type="hidden" name="period" value="{{ $period }}">
      </div>

      <button type="submit"
        class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
        Tampilkan
      </button>
    </form>
  </div>

  <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
    <form method="POST" action="{{ route('kpi.so.handling.save') }}">
      @csrf
      <input type="hidden" name="period" value="{{ $period }}">

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
            <tr class="text-left text-slate-600 border-b">
              <th class="py-2 pr-3">SO</th>
              <th class="py-2 pr-3">AO Code</th>
              <th class="py-2 pr-3">Target Handling</th>
              <th class="py-2 pr-3">Realisasi Handling</th>
            </tr>
          </thead>
          <tbody>
            @foreach($users as $i => $u)
              @php
                $t = $targets->get($u->id);
                $m = $monthlies->get($u->id);
                $targetAct = (int)($t->target_activity ?? 0);
                $actualAct = old("rows.$i.activity_actual", (int)($m->activity_actual ?? 0));
              @endphp
              <tr class="border-b">
                <td class="py-2 pr-3 font-semibold text-slate-900">
                  {{ $u->name }}
                  <div class="text-xs text-slate-500">{{ $u->level }}</div>
                </td>
                <td class="py-2 pr-3 text-slate-700">{{ $u->ao_code }}</td>
                <td class="py-2 pr-3 text-slate-700">{{ number_format($targetAct) }}</td>
                <td class="py-2 pr-3">
                  <input type="hidden" name="rows[{{ $i }}][user_id]" value="{{ $u->id }}">
                  <input type="number" min="0" name="rows[{{ $i }}][activity_actual]"
                    value="{{ $actualAct }}"
                    class="w-40 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      <div class="mt-4 flex justify-end">
        <button type="submit"
          class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
          Simpan Realisasi
        </button>
      </div>
    </form>
  </div>
</div>
@endsection

@section('scripts')
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const monthInput = document.querySelector('input[name="period_month"]');
    const hiddenPeriod = document.querySelector('input[name="period"]');
    if (!monthInput || !hiddenPeriod) return;
    const sync = () => {
      const v = (monthInput.value || '').trim();
      if (!v) return;
      hiddenPeriod.value = v + '-01';
    };
    monthInput.addEventListener('change', sync);
    sync();
  });
</script>
@endsection
