@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4">
  <div class="flex items-center justify-between mb-4 gap-2">
    <div>
      <h1 class="text-2xl font-bold text-slate-900">Target KPI Marketing</h1>
      <p class="text-sm text-slate-500">Draft → Submit → Approval.</p>
    </div>

    <div class="flex items-center gap-2">
      <a href="{{ route('kpi.marketing.targets.create') }}"
        class="rounded-xl bg-slate-900 px-4 py-2 text-white text-sm font-semibold hover:bg-slate-800">
        + Buat Target
      </a>

      <a href="{{ route('kpi.marketing.achievements.index') }}"
         class="rounded-xl border border-slate-300 px-4 py-2 text-slate-700 text-sm font-semibold hover:bg-slate-50">
        History Achievement
      </a>
    </div>
  </div>

  @if(session('status'))
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm">
      {{ session('status') }}
    </div>
  @endif

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
    <table class="w-full text-sm">
      <thead class="bg-slate-50 text-slate-600">
        <tr>
          <th class="text-left px-4 py-3">Periode</th>
          <th class="text-right px-4 py-3">Target OS Growth</th>
          <th class="text-right px-4 py-3">Target NOA</th>
          <th class="text-left px-4 py-3">Status</th>
          <th class="text-right px-4 py-3">Aksi</th>
        </tr>
      </thead>

      <tbody class="divide-y divide-slate-100">
        @forelse($targets as $t)
          <tr>
            <td class="px-4 py-3 font-semibold text-slate-900">
              {{ \Carbon\Carbon::parse($t->period)->format('M Y') }}
            </td>

            <td class="px-4 py-3 text-right">
              Rp {{ number_format($t->target_os_growth, 0, ',', '.') }}
            </td>

            <td class="px-4 py-3 text-right">
              {{ number_format($t->target_noa) }}
            </td>

            <td class="px-4 py-3">
              <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold
                {{ $t->status === \App\Models\MarketingKpiTarget::STATUS_DRAFT ? 'bg-slate-100 text-slate-700' : '' }}
                {{ in_array($t->status, [\App\Models\MarketingKpiTarget::STATUS_PENDING_TL, \App\Models\MarketingKpiTarget::STATUS_PENDING_KASI], true) ? 'bg-amber-100 text-amber-800' : '' }}
                {{ $t->status === \App\Models\MarketingKpiTarget::STATUS_APPROVED ? 'bg-emerald-100 text-emerald-800' : '' }}
                {{ $t->status === \App\Models\MarketingKpiTarget::STATUS_REJECTED ? 'bg-rose-100 text-rose-800' : '' }}
              ">
                @php
                    $label = match ($t->status) {
                        \App\Models\MarketingKpiTarget::STATUS_DRAFT => 'DRAFT',
                        \App\Models\MarketingKpiTarget::STATUS_PENDING_TL => 'PENDING TL',
                        \App\Models\MarketingKpiTarget::STATUS_PENDING_KASI => 'PENDING KASI',
                        \App\Models\MarketingKpiTarget::STATUS_APPROVED => 'APPROVED',
                        \App\Models\MarketingKpiTarget::STATUS_REJECTED => 'REJECTED',
                        default => $t->status,
                    };
                @endphp
                {{ $label }}
              </span>
            </td>

            <td class="px-4 py-3 text-right space-x-2">
              @if(!$t->is_locked && $t->status === \App\Models\MarketingKpiTarget::STATUS_DRAFT)
                <a href="{{ route('kpi.marketing.targets.edit', $t) }}"
                   class="inline-flex items-center rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                  Edit
                </a>
              @else
                <span class="inline-flex items-center rounded-xl border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-400 cursor-not-allowed">
                  Terkunci
                </span>
              @endif

              {{-- tombol pencapaian --}}
              @if($t->achievement)
                <a href="{{ route('kpi.marketing.targets.achievement', $t) }}"
                   class="inline-flex items-center rounded-xl border border-sky-200 px-3 py-1.5 text-xs font-semibold text-sky-700 hover:bg-sky-50">
                  Pencapaian
                </a>
              @else
                {{-- opsional: kalau mau tetap tampilkan tombol, tapi hitung saat dibuka --}}
                <a href="{{ route('kpi.marketing.targets.achievement', $t) }}"
                   class="inline-flex items-center rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                  Pencapaian
                </a>
              @endif
            </td>
          </tr>
        @empty
          <tr>
            <td colspan="5" class="px-4 py-6 text-center text-slate-500">
              Belum ada target. Klik <b>+ Buat Target</b>.
            </td>
          </tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="mt-4">
    {{ $targets->links() }}
  </div>
</div>
@endsection
