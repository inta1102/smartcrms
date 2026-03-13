@extends('layouts.app')

@section('content')
@php
    $fmt = fn($v) => number_format((float) $v, 0, ',', '.');
@endphp

<div class="max-w-5xl mx-auto space-y-6">
    <div class="rounded-[28px] border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-8 py-7 border-b border-slate-200">
            <h1 class="text-[26px] font-extrabold tracking-tight text-slate-900">
                Input Target Dashboard Dekom
            </h1>
            <p class="mt-2 text-[15px] text-slate-500">
                Input target bulanan untuk pencairan, outstanding, dan NPL.
            </p>
        </div>

        <div class="p-8">
            @if(session('success'))
                <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                    {{ session('success') }}
                </div>
            @endif

            <form method="POST" action="{{ route('dashboard.dekom.targets.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-5">
                @csrf

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Periode</label>
                    <input
                        type="month"
                        name="period_month"
                        value="{{ old('period_month', $period) }}"
                        class="w-full rounded-2xl border border-slate-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-slate-300"
                        required
                    >
                    @error('period_month')
                        <div class="mt-1 text-sm text-rose-600">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Target Pencairan</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="target_disbursement"
                        value="{{ old('target_disbursement', $row->target_disbursement ?? 0) }}"
                        class="w-full rounded-2xl border border-slate-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-slate-300"
                        required
                    >
                    @error('target_disbursement')
                        <div class="mt-1 text-sm text-rose-600">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Target OS</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="target_os"
                        value="{{ old('target_os', $row->target_os ?? 0) }}"
                        class="w-full rounded-2xl border border-slate-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-slate-300"
                        required
                    >
                    @error('target_os')
                        <div class="mt-1 text-sm text-rose-600">{{ $message }}</div>
                    @enderror
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-2">Target NPL (%)</label>
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        name="target_npl_pct"
                        value="{{ old('target_npl_pct', $row->target_npl_pct ?? 0) }}"
                        class="w-full rounded-2xl border border-slate-300 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-slate-300"
                        required
                    >
                    @error('target_npl_pct')
                        <div class="mt-1 text-sm text-rose-600">{{ $message }}</div>
                    @enderror
                </div>

                <div class="md:col-span-2 flex items-center justify-end pt-2">
                    <button
                        type="submit"
                        class="inline-flex items-center rounded-2xl bg-slate-900 px-6 py-3 text-sm font-bold text-white hover:bg-slate-800"
                    >
                        Simpan Target
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="rounded-[28px] border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-8 py-7 border-b border-slate-200">
            <h2 class="text-[22px] font-extrabold text-slate-900">
                Ringkasan Target
            </h2>
            <p class="mt-2 text-[15px] text-slate-500">
                Posisi periode {{ $periodLabel }}.
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 p-8">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Target Pencairan</div>
                <div class="mt-2 text-2xl font-extrabold text-slate-900">
                    Rp {{ $fmt($row->target_disbursement ?? 0) }}
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Target OS</div>
                <div class="mt-2 text-2xl font-extrabold text-slate-900">
                    Rp {{ $fmt($row->target_os ?? 0) }}
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <div class="text-xs font-bold uppercase tracking-wide text-slate-500">Target NPL</div>
                <div class="mt-2 text-2xl font-extrabold text-slate-900">
                    {{ number_format((float) ($row->target_npl_pct ?? 0), 2, ',', '.') }}%
                </div>
            </div>
        </div>
    </div>
</div>
@endsection