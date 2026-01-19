<div
    x-data="{
        tab: (new URLSearchParams(window.location.search).get('tab')) ?? 'overview',
        setTab(t){
            this.tab = t;
            const url = new URL(window.location);
            url.searchParams.set('tab', t);
            window.history.replaceState({}, '', url);
        }
    }"
    class="rounded-2xl border border-slate-200 bg-white shadow-sm"
>
    <style>[x-cloak]{display:none!important}</style>

    {{-- TAB HEADER --}}
    <div class="border-b border-slate-200 px-5 pt-4">
        @php
            $activeTargetCount = 0;
            if (method_exists($action, 'resolutionTargets')) {
                $activeTargetCount = $action->resolutionTargets->where('is_active', true)->count();
            }
        @endphp

        <nav class="flex flex-wrap gap-2 pb-3">

            {{-- Overview --}}
            <button
                type="button"
                @click.prevent="setTab('overview')"
                :class="tab === 'overview'
                    ? 'bg-indigo-600 text-white shadow-sm'
                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition"
            >
                <span>📌</span>
                <span>Overview</span>
            </button>

            <!-- {{-- Target Penyelesaian --}}
            @if(($action->action_type ?? '') === \App\Models\LegalAction::TYPE_HT_EXECUTION)
                <button
                    type="button"
                    @click.prevent="setTab('target_resolution')"
                    :class="tab === 'target_resolution'
                        ? 'bg-indigo-600 text-white shadow-sm'
                        : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                    class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition"
                >
                    <span>🎯</span>
                    <span>Target Penyelesaian</span>

                    {{-- Badge kecil: 1 kalau ada target aktif, 0 kalau belum --}}
                    <span
                        :class="tab === 'target_resolution' ? 'bg-white/20 text-white' : 'bg-white text-slate-700'"
                        class="ml-1 rounded-full px-2 py-0.5 text-xs font-bold"
                    >
                        {{ $action->resolutionTargets?->where('is_active', true)->count() ?? 0 }}
                    </span>
                </button>
            @endif -->

            {{-- Status Log --}}
            <button
                type="button"
                @click.prevent="setTab('status')"
                :class="tab === 'status'
                    ? 'bg-indigo-600 text-white shadow-sm'
                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition"
            >
                <span>🧾</span>
                <span>Status Log</span>
                <span
                    :class="tab === 'status' ? 'bg-white/20 text-white' : 'bg-white text-slate-700'"
                    class="ml-1 rounded-full px-2 py-0.5 text-xs font-bold"
                >
                    {{ $action->statusLogs->count() }}
                </span>
            </button>

            {{-- Dokumen --}}
            <button
                type="button"
                @click.prevent="setTab('documents')"
                :class="tab === 'documents'
                    ? 'bg-indigo-600 text-white shadow-sm'
                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition"
            >
                <span>📎</span>
                <span>Dokumen</span>
                <span
                    :class="tab === 'documents' ? 'bg-white/20 text-white' : 'bg-white text-slate-700'"
                    class="ml-1 rounded-full px-2 py-0.5 text-xs font-bold"
                >
                    {{ $action->documents->count() }}
                </span>
            </button>

            {{-- Events --}}
            <button
                type="button"
                @click.prevent="setTab('events')"
                :class="tab === 'events'
                    ? 'bg-indigo-600 text-white shadow-sm'
                    : 'bg-amber-50 text-amber-800 hover:bg-amber-100'"
                class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition"
            >
                <span>⏰</span>
                <span>Events</span>
                <span
                    :class="tab === 'events' ? 'bg-white/20 text-white' : 'bg-amber-200 text-amber-900'"
                    class="ml-1 rounded-full px-2 py-0.5 text-xs font-bold"
                >
                    {{ $action->events->where('status','scheduled')->count() }}
                </span>
            </button>

            {{-- Biaya --}}
            <button
                type="button"
                @click.prevent="setTab('costs')"
                :class="tab === 'costs'
                    ? 'bg-indigo-600 text-white shadow-sm'
                    : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition"
            >
                <span>💸</span>
                <span>Biaya</span>
                <span
                    :class="tab === 'costs' ? 'bg-white/20 text-white' : 'bg-white text-slate-700'"
                    class="ml-1 rounded-full px-2 py-0.5 text-xs font-bold"
                >
                    {{ $action->costs->count() }}
                </span>
            </button>

        </nav>
    </div>

    {{-- TAB CONTENT --}}
    <div class="p-5" x-cloak>
        <div x-show="tab === 'overview'" x-transition.opacity>
            @include('legal.actions.tabs.overview', ['action'=>$action])
        </div>

        @if(($action->action_type ?? '') === \App\Models\LegalAction::TYPE_HT_EXECUTION)
            <div x-show="tab === 'target_resolution'" x-transition.opacity>
                @include('legal.actions.tabs.target_resolution', ['action'=>$action])
            </div>
        @endif

        <div x-show="tab === 'status'" x-transition.opacity>
            @include('legal.actions.tabs.status', ['action'=>$action])
        </div>

        <div x-show="tab === 'documents'" x-transition.opacity>
            @include('legal.actions.tabs.documents', ['action'=>$action])
        </div>

        <div x-show="tab === 'events'" x-transition.opacity>
            @include('legal.actions.tabs.events', ['action'=>$action])
        </div>

        <div x-show="tab === 'costs'" x-transition.opacity>
            @include('legal.actions.tabs.costs', ['action'=>$action])
        </div>
    </div>
</div>
