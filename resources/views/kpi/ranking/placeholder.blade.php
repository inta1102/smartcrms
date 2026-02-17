@extends('layouts.app')

@section('title', 'Ranking KPI')

@section('content')
<div class="max-w-4xl mx-auto p-6">
  <div class="rounded-2xl border border-slate-200 bg-white p-6">
    <div class="text-2xl font-extrabold text-slate-900">Ranking KPI {{ $role }}</div>
    <div class="text-sm text-slate-500 mt-1">Periode: {{ \Carbon\Carbon::parse($periodYmd)->translatedFormat('F Y') }}</div>
    <div class="mt-4 text-slate-700">
      Modul ranking untuk role <b>{{ $role }}</b> belum diaktifkan. Kita aktifkan bertahap setelah AO stabil.
    </div>
  </div>
</div>
@endsection
