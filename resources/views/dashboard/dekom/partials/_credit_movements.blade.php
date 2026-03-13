@php
    $movementSections = $movementSections ?? [];

    $nplImprovement = $movementSections['npl_improvement'] ?? collect();
    $qualityImprovement = $movementSections['quality_improvement'] ?? collect();
    $qualityDeterioration = $movementSections['quality_deterioration'] ?? collect();
    $creditActivity = $movementSections['credit_activity'] ?? collect();

    $fmtMoney = $fmtMoney ?? fn ($v) => 'Rp ' . number_format((float) $v, 0, ',', '.');

    $groupBySubgroup = function ($rows) {
        return collect($rows)->groupBy(fn ($r) => $r->subgroup ?: 'summary');
    };
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 shadow-sm">
    <div class="mb-4">
        <div class="text-base font-bold text-slate-900">Dinamika Portofolio Kredit</div>
        <div class="mt-1 text-sm text-slate-500">
            Pergerakan kualitas kredit, perbaikan kredit bermasalah, dan aktivitas kredit periode berjalan.
        </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">

        @include('dashboard.dekom.partials._credit_movement_table', [
            'title' => 'Perbaikan Kredit Bermasalah (NPL)',
            'rows' => $nplImprovement,
            'tone' => 'emerald',
            'showPlafondBaru' => false,
            'grouped' => false,
        ])

        @include('dashboard.dekom.partials._credit_movement_table', [
            'title' => 'Perbaikan Kualitas Kredit',
            'rows' => $qualityImprovement,
            'tone' => 'emerald',
            'showPlafondBaru' => false,
            'grouped' => true,
        ])

        @include('dashboard.dekom.partials._credit_movement_table', [
            'title' => 'Penurunan Kualitas Kredit',
            'rows' => $qualityDeterioration,
            'tone' => 'rose',
            'showPlafondBaru' => false,
            'grouped' => false,
        ])

        @include('dashboard.dekom.partials._credit_movement_table', [
            'title' => 'Aktivitas Kredit',
            'rows' => $creditActivity,
            'tone' => 'slate',
            'showPlafondBaru' => true,
            'grouped' => true,
        ])

    </div>
</div>