<div class="grid grid-cols-1 gap-4 md:grid-cols-2">

    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="mb-3 text-sm font-semibold text-slate-800">Ringkasan</h3>

        <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Kasus</dt>
                <dd class="font-semibold text-slate-800">{{ $action->legalCase?->legal_case_no ?? '-' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Debitur</dt>
                <dd class="font-semibold text-slate-800">{{ $action->legalCase?->nplCase?->loanAccount?->customer_name ?? '-' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-slate-500">Institusi Eksternal</dt>
                <dd class="font-semibold text-slate-800">{{ $action->external_institution ?? '-' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt class="text-slate-500">External Ref</dt>
                <dd class="font-semibold text-slate-800">{{ $action->external_ref_no ?? '-' }}</dd>
            </div>
        </dl>
    </div>

    <div class="rounded-xl border border-slate-200 bg-white p-4">
        <h3 class="mb-3 text-sm font-semibold text-slate-800">Timeline (Events)</h3>

        @forelse($action->events as $ev)
            <div class="border-b border-slate-100 py-3 last:border-b-0">
                <div class="flex items-center justify-between gap-3">
                    <div class="text-sm font-semibold text-slate-800">
                        {{ $ev->title ?? 'Event' }}
                    </div>
                    <div class="text-xs text-slate-500">
                        {{ optional($ev->event_date ?? $ev->created_at)->format('d M Y') }}
                    </div>
                </div>

                @if(!empty($ev->notes))
                    <div class="mt-1 text-sm text-slate-600">
                        {{ $ev->notes }}
                    </div>
                @endif
            </div>
        @empty
            <div class="text-sm text-slate-500">Belum ada event.</div>
        @endforelse
    </div>

</div>
