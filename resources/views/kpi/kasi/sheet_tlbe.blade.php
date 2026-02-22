@if($role==='KSBE')
  <div class="rounded-2xl border bg-white p-4 mb-5">
    <div class="text-lg font-extrabold">Recap KSBE</div>
    <div class="mt-2 grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
      <div class="rounded-xl border p-3">
        <div class="text-slate-500 text-xs">Total BE</div>
        <div class="text-2xl font-black">{{ $recap['count'] ?? 0 }}</div>
      </div>
      <div class="rounded-xl border p-3">
        <div class="text-slate-500 text-xs">Avg PI</div>
        <div class="text-2xl font-black">{{ number_format((float)($recap['avg_pi'] ?? 0),2) }}</div>
      </div>
      <div class="rounded-xl border p-3">
        <div class="text-slate-500 text-xs">Best</div>
        <div class="font-bold">{{ $recap['best'] ?? '-' }}</div>
      </div>
      <div class="rounded-xl border p-3">
        <div class="text-slate-500 text-xs">Worst</div>
        <div class="font-bold">{{ $recap['worst'] ?? '-' }}</div>
      </div>
    </div>

    @if(!empty($insights['target_all_zero']))
      <div class="mt-4 text-sm">
        <div class="font-bold text-rose-700">Warning: target masih 0</div>
        <div class="text-slate-600">{{ implode(', ', $insights['target_all_zero']) }}</div>
      </div>
    @endif
  </div>

  {{-- reuse: render daftar BE pakai partial yang sudah kamu bikin --}}
  @include('kpi.marketing.partials.sheet_be', ['items'=>$items, 'weights'=>$weights])
@endif