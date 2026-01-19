@props([
    'status' => null,
    'size' => 'sm', // sm | md
])

@php
    $s = strtolower((string) $status);

    // Size
    $sizeCls = match ($size) {
        'md' => 'text-xs px-2.5 py-1',
        default => 'text-[11px] px-2 py-0.5',
    };

    // Color mapping
    $map = [
        'draft'      => 'bg-gray-100 text-gray-700 ring-gray-200',
        'submitted'  => 'bg-blue-50 text-blue-700 ring-blue-200',
        'in_progress'=> 'bg-indigo-50 text-indigo-700 ring-indigo-200',
        'waiting'    => 'bg-amber-50 text-amber-800 ring-amber-200',

        'completed'  => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'done'       => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'success'    => 'bg-emerald-50 text-emerald-700 ring-emerald-200',

        'failed'     => 'bg-red-50 text-red-700 ring-red-200',
        'cancelled'  => 'bg-slate-100 text-slate-700 ring-slate-200',
        'canceled'   => 'bg-slate-100 text-slate-700 ring-slate-200',

        'open'       => 'bg-violet-50 text-violet-700 ring-violet-200',
        'closed'     => 'bg-slate-100 text-slate-700 ring-slate-200',
    ];

    $cls = $map[$s] ?? 'bg-gray-100 text-gray-700 ring-gray-200';

    $label = strtoupper(str_replace('_',' ', $s ?: '-'));
@endphp

<span class="inline-flex items-center rounded-full ring-1 ring-inset {{ $cls }} {{ $sizeCls }} font-semibold">
    {{ $label }}
</span>
