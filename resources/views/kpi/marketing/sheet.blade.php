@extends('layouts.app') 

@section('title', 'KPI Sheet')

@section('content')
@php
  // Period (Y-m) untuk filter
  $periodYm = $periodYm ?? request('period', now()->format('Y-m'));

  // Role selector (AO|SO|RO|FE|BE|KSBE)
  $roleSel  = $role ?? strtoupper((string)request('role', 'AO'));
  if (!in_array($roleSel, ['AO','SO','RO','FE','BE','KSBE'], true)) $roleSel = 'AO';

  // Param period Y-m-01 (buat beberapa route yang butuh tanggal)
  $periodYmd = $periodYm . '-01';

  // akses input komunitas only KBL & SO
  $canInputSoCommunity = ($roleSel === 'SO') && auth()->user()?->hasAnyRole(['KBL']);
  $canManageTargets = auth()->user()?->hasAnyRole(['KBL']);

  // ✅ Normalisasi items agar aman (Collection / array / null)
  $itemsCol = $items instanceof \Illuminate\Support\Collection ? $items : collect($items ?? []);
@endphp



<div class="max-w-6xl mx-auto p-4">

  {{-- HEADER --}}
  <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-5">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">📄 KPI {{ $roleSel }} – Sheet</h1>
      <p class="text-sm text-slate-500">
        Periode: <b>{{ $period->translatedFormat('M Y') }}</b>
      </p>
    </div>

    <div class="flex items-center gap-2 flex-wrap">

      {{-- =========================
           FILTER (GET)
           ========================= --}}
      <form method="GET" class="flex items-center gap-2 flex-wrap">
        
        @php
          // Role options untuk dropdown
          // kalau mau batasi: tetap pastikan role aktif selalu masuk
          $roleOptions = ['AO','SO','RO','FE','BE','KSBE'];
          if (!in_array($roleSel, $roleOptions, true)) $roleOptions[] = $roleSel;
        @endphp

        <select name="role" class="rounded-xl border border-slate-300 px-3 py-2 text-sm">
          @foreach($roleOptions as $r)
            <option value="{{ $r }}" @selected($roleSel === $r)>{{ $r }}</option>
          @endforeach
        </select>

        <input type="month"
               name="period"
               value="{{ $periodYm }}"
               class="rounded-xl border border-slate-300 px-3 py-2 text-sm"/>

        <button class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
          Tampilkan
        </button>
      </form>

      {{-- =========================
           ACTIONS (OUTSIDE GET FORM)
           ========================= --}}
      <div class="flex gap-2 flex-wrap">

        {{-- Buat target SO (KBL only) --}}
        @if($roleSel === 'SO' && auth()->user()?->hasAnyRole(['KBL']))
          <a href="{{ route('kpi.so.targets.index', ['period' => $periodYm]) }}"
             class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
            ✍️ Buat Target SO
          </a>
        @endif

        @if($roleSel === 'RO' && $canManageTargets)
          <a href="{{ route('kpi.ro.targets.index', ['period' => $periodYm]) }}"
            class="rounded-xl bg-emerald-600 px-4 py-2 text-white text-sm font-semibold hover:bg-emerald-700">
            🎯 Input Target RO
          </a>
        @endif

        @if($roleSel === 'FE' && $canManageTargets)
          <a href="{{ route('kpi.fe.targets.index', ['period' => $periodYm]) }}"
            class="rounded-xl bg-emerald-600 px-4 py-2 text-white text-sm font-semibold hover:bg-emerald-700">
            🎯 Input Target FE
          </a>
        @endif

        @if($roleSel === 'BE' && $canManageTargets)
          <a href="{{ route('kpi.be.targets.index', ['period' => $periodYm]) }}"
            class="rounded-xl bg-emerald-600 px-4 py-2 text-white text-sm font-semibold hover:bg-emerald-700">
            🎯 Input Target BE
          </a>
        @endif

        @if($roleSel === 'AO' && $canManageTargets)
          <a href="{{ route('kpi.ao.targets.index', ['period' => $periodYm]) }}"
            class="rounded-xl bg-emerald-600 px-4 py-2 text-white text-sm font-semibold hover:bg-emerald-700">
            🎯 Input Target AO
          </a>
        @endif

        {{-- Input Komunitas & Adjustment (KBL only, SO only) --}}
        @if($canInputSoCommunity)
          <a href="{{ route('kpi.so.community_input.index', ['period' => $periodYmd]) }}"
             class="rounded-xl bg-indigo-600 px-4 py-2 text-white text-sm font-semibold hover:bg-indigo-700">
            ✍️ Input Komunitas & Adjustment
          </a>
        @endif

        {{-- Recalc per role --}}
        @can('recalcMarketingKpi')

          @if($roleSel === 'AO')
            <form method="POST" action="{{ route('kpi.recalc.ao') }}"
                  onsubmit="return confirm('Recalc KPI AO untuk periode ini?')">
              @csrf
              <input type="hidden" name="period" value="{{ $periodYm }}">
              <button class="rounded-xl bg-amber-600 px-4 py-2 text-white text-sm font-semibold hover:bg-amber-700">
                🔄 Recalc AO
              </button>
            </form>

          @elseif($roleSel === 'SO')
            <form method="POST" action="{{ route('kpi.recalc.so') }}"
                  onsubmit="return confirm('Recalc KPI SO untuk periode ini?')">
              @csrf
              <input type="hidden" name="period" value="{{ $periodYm }}">
              <button class="rounded-xl bg-sky-600 px-4 py-2 text-white text-sm font-semibold hover:bg-sky-700">
                🔄 Recalc SO
              </button>
            </form>

          @elseif($roleSel === 'RO')
            {{-- ✅ Recalc RO (mode auto: bulan ini realtime, bulan lalu kebawah eom) --}}
            <form method="POST" action="{{ route('kpi.recalc.ro') }}"
                  onsubmit="return confirm('Recalc KPI RO untuk periode ini?')">
              @csrf
              <input type="hidden" name="period" value="{{ $periodYm }}">
              <button class="rounded-xl bg-orange-600 px-4 py-2 text-white text-sm font-semibold hover:bg-orange-700">
                🔄 Recalc RO
              </button>
            </form>
        
          @elseif($roleSel === 'FE')
            {{-- ✅ Recalc FE --}}
            <form method="POST" action="{{ route('kpi.recalc.fe') }}"
                  onsubmit="return confirm('Recalc KPI FE untuk periode ini?')">
              @csrf
              <input type="hidden" name="period" value="{{ $periodYm ?? now()->format('Y-m') }}">
              <button class="rounded-xl bg-orange-600 px-4 py-2 text-white text-sm font-semibold hover:bg-orange-700">
                🔄 Recalc FE
              </button>
            </form>
          
          @elseif(in_array($roleSel, ['BE','KSBE'], true))
            {{-- ✅ Recalc BE (untuk KSBE juga boleh trigger perhitungan data bawahan) --}}
            <form method="POST" action="{{ route('kpi.recalc.be') }}"
                  onsubmit="return confirm('Recalc KPI BE untuk periode ini?')">
              @csrf
              <input type="hidden" name="period" value="{{ $periodYm ?? now()->format('Y-m') }}">
              <button class="rounded-xl bg-orange-600 px-4 py-2 text-white text-sm font-semibold hover:bg-orange-700">
                🔄 Recalc BE
              </button>
            </form>
          @endif

        @endcan

      </div>
    </div>
  </div>

  {{-- BODY --}}
  @if($itemsCol->isEmpty())
    <div class="rounded-2xl border border-slate-200 bg-white p-6 text-slate-600">
      Belum ada data KPI {{ $roleSel }} untuk periode ini. Jalankan <b>Recalc</b> untuk menghitung KPI.
    </div>
  @else
    {{-- Render per role --}}
    @if($roleSel === 'AO')
      @include('kpi.marketing.partials.sheet_ao')
    @elseif($roleSel === 'SO')
      @include('kpi.marketing.partials.sheet_so')
    @elseif($roleSel === 'RO')
      @include('kpi.marketing.partials.sheet_ro')
    @elseif($roleSel === 'FE')
      @include('kpi.marketing.partials.sheet_fe')
    @elseif($roleSel === 'BE')
      @include('kpi.marketing.partials.sheet_be')
    @elseif($roleSel === 'KSBE')
      @include('kpi.marketing.partials.sheet_ksbe')
    @endif
  @endif

</div>
@endsection