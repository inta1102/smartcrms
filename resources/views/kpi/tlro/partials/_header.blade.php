@php
  $sum = $sum ?? 'mtd';

  if (!isset($urlDay) || !isset($urlMtd) || !isset($activeDay) || !isset($activeMtd)) {
      $q = request()->query();

      $buildUrl = function(array $override = []) use ($q) {
          $merged = array_merge($q, $override);
          foreach ($merged as $k => $v) {
              if ($v === null || $v === '') unset($merged[$k]);
          }
          return url()->current() . (count($merged) ? ('?' . http_build_query($merged)) : '');
      };

      $urlDay = $urlDay ?? $buildUrl(['sum' => 'day']);
      $urlMtd = $urlMtd ?? $buildUrl(['sum' => 'mtd']);
      $activeDay = $activeDay ?? ($sum === 'day');
      $activeMtd = $activeMtd ?? ($sum === 'mtd');
  }
@endphp

<div class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
  <div>
    <h1 class="text-2xl font-extrabold text-slate-900">📈 Dashboard TL RO</h1>

    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
      <span class="ml-0 md:ml-2 inline-flex rounded-xl border border-slate-200 overflow-hidden bg-white">
        <a href="{{ $urlDay }}"
           class="px-3 py-1.5 text-xs font-semibold {{ $activeDay ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50' }}">
          Harian
        </a>
        <a href="{{ $urlMtd }}"
           class="px-3 py-1.5 text-xs font-semibold {{ $activeMtd ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-50' }}">
          MTD
        </a>
      </span>

      @if(!empty($compareLabel))
        <span class="px-3 py-1 rounded-xl bg-white border border-slate-200 text-slate-600">
          {{ $compareLabel }}
        </span>
      @endif
    </div>
  </div>

  <form method="GET" class="flex items-end gap-2 flex-wrap">
    <input type="hidden" name="sum" value="{{ $sum }}">

    <div>
      <div class="text-xs text-slate-500 mb-1">AO</div>
      <select name="ao" class="rounded-xl border border-slate-300 px-3 py-2 text-sm bg-white">
        <option value="">ALL (Scope TL)</option>
        @foreach(($aoOptions ?? []) as $o)
          <option value="{{ $o['ao_code'] }}" {{ ($aoFilter ?? '') === $o['ao_code'] ? 'selected' : '' }}>
            {{ $o['label'] }}
          </option>
        @endforeach
      </select>
    </div>

    <div>
      <div class="text-xs text-slate-500 mb-1">Dari</div>
      <input type="date" name="from" value="{{ $from }}"
             class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
    </div>

    <div>
      <div class="text-xs text-slate-500 mb-1">Sampai</div>
      <input type="date" name="to" value="{{ $to }}"
             class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
    </div>

    <button class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
      Tampilkan
    </button>
  </form>
</div>