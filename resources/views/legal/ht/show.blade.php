@extends('layouts.app')

@section('content')
@php
    $tab = $tab ?? 'summary';

    $is = fn($t) => $tab === $t;

    $status = strtolower((string) ($action->status ?? 'draft'));
    $exec   = $action->htExecution;

    $pill = function(string $s) {
        $map = [
            'draft'       => 'bg-slate-100 text-slate-700 border-slate-200',
            'prepared'    => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'submitted'   => 'bg-amber-50 text-amber-700 border-amber-200',
            'in_progress' => 'bg-blue-50 text-blue-700 border-blue-200',
            'executed'    => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'settled'     => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'closed'      => 'bg-slate-200 text-slate-800 border-slate-300',
            'cancelled'   => 'bg-rose-50 text-rose-700 border-rose-200',
        ];
        return $map[$s] ?? 'bg-slate-100 text-slate-700 border-slate-200';
    };

    $methodLabel = function($m){
        return match($m){
            'parate' => 'Parate Eksekusi (Lelang KPKNL)',
            'pn' => 'Eksekusi via PN (Fiat + Lelang)',
            'bawah_tangan' => 'Penjualan di Bawah Tangan',
            default => '-',
        };
    };

    $requiredTotal = $action->htDocuments->where('is_required', true)->count();
    $requiredVerified = $action->htDocuments->where('is_required', true)->where('status','verified')->count();

    $canMutate = !$readOnly; // readOnly dari locked_at
@endphp

<div class="max-w-6xl mx-auto px-4 py-6">
    {{-- Header --}}
    <div class="mb-5 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-xs text-slate-500">Legal Action</div>
                <div class="mt-1 text-lg font-semibold text-slate-900">
                    Eksekusi Hak Tanggungan
                </div>
                <div class="mt-1 text-sm text-slate-600">
                    Case: <span class="font-semibold">{{ $action->legalCase?->legal_case_no ?? '-' }}</span>
                    • Debitur: <span class="font-semibold">{{ $action->legalCase?->debtor_name ?? ($action->legalCase?->nplCase?->loanAccount?->customer_name ?? '-') }}</span>
                </div>
            </div>

            <div class="text-right">
                <div class="inline-flex items-center gap-2 rounded-xl border px-3 py-1.5 text-xs font-semibold {{ $pill($status) }}">
                    <span class="inline-block h-2 w-2 rounded-full bg-current opacity-60"></span>
                    <span>{{ strtoupper(str_replace('_',' ', $status)) }}</span>
                </div>

                @if($readOnly)
                    <div class="mt-2 text-xs text-amber-700">
                        <span class="inline-flex items-center gap-1 rounded-lg bg-amber-50 px-2 py-1 border border-amber-200">
                            🔒 Read-only (submitted+)
                        </span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Alerts --}}
        @if(session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                ✅ {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                <div class="font-semibold">Terjadi error:</div>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    @foreach($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Mini Summary --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <div class="text-xs text-slate-500">Metode</div>
                <div class="mt-1 text-sm font-semibold text-slate-800">{{ $methodLabel($exec?->method) }}</div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <div class="text-xs text-slate-500">Objek</div>
                <div class="mt-1 text-sm font-semibold text-slate-800">
                    {{ $exec?->land_cert_type ? $exec->land_cert_type : '-' }} {{ $exec?->land_cert_no ?? '' }}
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <div class="text-xs text-slate-500">Nilai Taksasi</div>
                <div class="mt-1 text-sm font-semibold text-slate-800">
                    {{ $exec?->appraisal_value ? number_format((float)$exec->appraisal_value, 0, ',', '.') : '-' }}
                </div>
            </div>
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <div class="text-xs text-slate-500">Dokumen Wajib</div>
                <div class="mt-1 text-sm font-semibold text-slate-800">
                    {{ $requiredVerified }}/{{ $requiredTotal }} Verified
                </div>
            </div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="mb-4 rounded-2xl border border-slate-200 bg-white p-2 shadow-sm">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('legal-actions.ht.show', $action) }}?tab=summary"
               class="px-4 py-2 rounded-xl text-sm font-semibold {{ $is('summary') ? 'bg-indigo-600 text-white' : 'text-slate-700 hover:bg-slate-100' }}">
                Ringkasan
            </a>
            <a href="{{ route('legal-actions.ht.show', $action) }}?tab=execution"
               class="px-4 py-2 rounded-xl text-sm font-semibold {{ $is('execution') ? 'bg-indigo-600 text-white' : 'text-slate-700 hover:bg-slate-100' }}">
                Data Objek & Dasar HT
            </a>
            <a href="{{ route('legal-actions.ht.show', $action) }}?tab=documents"
               class="px-4 py-2 rounded-xl text-sm font-semibold {{ $is('documents') ? 'bg-indigo-600 text-white' : 'text-slate-700 hover:bg-slate-100' }}">
                Dokumen & Checklist
            </a>
            <a href="{{ route('legal-actions.ht.show', $action) }}?tab=timeline"
               class="px-4 py-2 rounded-xl text-sm font-semibold {{ $is('timeline') ? 'bg-indigo-600 text-white' : 'text-slate-700 hover:bg-slate-100' }}">
                Proses & Timeline
            </a>

            <div class="ml-auto flex items-center gap-2">
                <a href="{{ route('legal-actions.show', $action) }}"
                   class="px-4 py-2 rounded-xl text-sm font-semibold border border-slate-200 text-slate-700 hover:bg-slate-50">
                    ← Kembali
                </a>
            </div>
        </div>
    </div>

    {{-- Tab content --}}
    <div class="space-y-4">
        @if($is('summary'))
            @include('legal.ht.partials.summary', ['action'=>$action, 'readOnly'=>$readOnly])
        @elseif($is('execution'))
            @include('legal.ht.partials.execution_form', ['action'=>$action, 'readOnly'=>$readOnly])
        @elseif($is('documents'))
            @include('legal.ht.partials.documents', ['action'=>$action, 'readOnly'=>$readOnly])
        @else
            @include('legal.ht.partials.timeline', ['action'=>$action, 'readOnly'=>$readOnly])
        @endif
    </div>
</div>
<x-status-transition-modal
    :actionId="$action->id"
    :route="route('legal-actions.update-status', $action)"
/>
@endsection
