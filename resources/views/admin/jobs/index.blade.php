@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-7xl p-4 space-y-4">

    {{-- Header --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h1 class="text-xl font-bold text-slate-900">⚙️ Job Monitor</h1>
            <p class="text-sm text-slate-500">Pantau antrian job (database queue) & kesehatan proses update data.</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.jobs.failed') }}"
               class="rounded-xl border px-3 py-2 text-sm hover:bg-slate-50">
               Lihat Failed Jobs ({{ $failedCount }})
            </a>

            <form method="POST" action="{{ route('admin.jobs.run.sync_users') }}">
                @csrf
                <input type="hidden" name="full" value="1">
                <button class="rounded-xl border px-3 py-2 text-sm hover:bg-slate-50">
                    Full SyncUsers
                </button>
            </form>
        </div>
    </div>

    {{-- HEALTH PANEL --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        {{-- Runner --}}
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">Queue Runner</div>

            <div class="mt-2 flex items-center gap-2">
                @php
                    $st = $health['runner']['status'] ?? 'down';
                    $badge = $st === 'ok' ? 'bg-green-100 text-green-700'
                        : ($st === 'warn' ? 'bg-yellow-100 text-yellow-700' : 'bg-red-100 text-red-700');
                    $label = strtoupper($st);
                @endphp

                <span class="px-2 py-1 rounded-full text-xs font-semibold {{ $badge }}">{{ $label }}</span>

                <div class="text-sm">
                    <span class="text-slate-500">last seen:</span>
                    <span class="font-medium">{{ $health['runner']['last_seen'] ?? '-' }}</span>
                    @if(!is_null($health['runner']['age_minutes'] ?? null))
                        <span class="text-slate-500">({{ $health['runner']['age_minutes'] }} min)</span>
                    @endif
                </div>
            </div>

            <div class="mt-2 text-xs text-slate-500">
                oldest pending: {{ $health['counts']['oldest_pending_minutes'] ?? '-' }} min
            </div>
        </div>

        {{-- Pending by Queue --}}
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">Pending by Queue</div>
            <div class="mt-3 space-y-2">
                @php $pbq = $health['pending_by_queue'] ?? []; @endphp

                @forelse($pbq as $r)
                    <div class="flex items-center justify-between text-sm">
                        <span class="font-medium">{{ $r->queue }}</span>
                        <span class="tabular-nums">{{ $r->total }}</span>
                    </div>
                @empty
                    <div class="text-sm text-slate-400">-</div>
                @endforelse
            </div>
        </div>

        {{-- Top Pending Jobs --}}
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">Top Pending Jobs</div>
            <div class="mt-3 space-y-2">
                @php $tpj = $health['top_pending_jobs'] ?? []; @endphp

                @forelse($tpj as $it)
                    <div class="flex items-center justify-between text-sm gap-3">
                        <span class="truncate" title="{{ $it['job'] }}">{{ $it['job'] }}</span>
                        <span class="tabular-nums shrink-0">{{ $it['total'] }}</span>
                    </div>
                @empty
                    <div class="text-sm text-slate-400">-</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- OPTIONAL: Batch Summary --}}
    @if(!empty($health['batches']))
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div class="text-sm font-semibold text-slate-800">Recent Batches</div>
                <div class="text-xs text-slate-500">
                    sumber: <span class="font-mono">job_batches</span>
                </div>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="text-slate-500">
                        <tr class="border-b">
                            <th class="text-left py-2 pr-4">Name</th>
                            <th class="text-right py-2 pr-4">Total</th>
                            <th class="text-right py-2 pr-4">Pending</th>
                            <th class="text-right py-2 pr-4">Failed</th>
                            <th class="text-left py-2 pr-4">Created</th>
                            <th class="text-left py-2">Finished</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($health['batches'] as $b)
                            <tr class="border-b last:border-b-0">
                                <td class="py-2 pr-4">
                                    <div class="font-medium text-slate-900">{{ $b['name'] }}</div>
                                    <div class="text-xs text-slate-500 font-mono truncate max-w-[360px]" title="{{ $b['id'] }}">
                                        {{ $b['id'] }}
                                    </div>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $b['total'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $b['pending'] }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $b['failed'] }}</td>
                                <td class="py-2 pr-4">{{ $b['created_at'] ?? '-' }}</td>
                                <td class="py-2">{{ $b['finished_at'] ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">Pending</div>
            <div class="text-2xl font-bold">{{ $pendingCount }}</div>
        </div>

        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">Processing</div>
            <div class="text-2xl font-bold">{{ $processingCount }}</div>
        </div>

        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">Failed</div>
            <div class="text-2xl font-bold">{{ $failedCount }}</div>
        </div>

        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">Oldest Pending</div>
            <div class="text-base font-semibold">{{ $oldestPendingAge ?? '-' }}</div>
        </div>

        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">Last SyncUsers (Success)</div>
            <div class="text-sm font-semibold text-slate-900">
                {{ $lastSyncSuccess?->ran_at?->format('Y-m-d H:i:s') ?? '-' }}
            </div>
            <div class="text-xs text-slate-500 mt-1">
                rows: {{ $lastSyncSuccess?->count ?? 0 }} |
                {{ $lastSyncSuccess?->duration_ms ? ($lastSyncSuccess->duration_ms . ' ms') : '-' }}
            </div>
        </div>

        <div class="rounded-2xl border bg-white p-4 shadow-sm">
            <div class="text-sm text-slate-500">Last SyncUsers (Failed)</div>
            <div class="text-sm font-semibold text-slate-900">
                {{ $lastSyncFailed?->ran_at?->format('Y-m-d H:i:s') ?? '-' }}
            </div>
            <div class="text-xs text-rose-600 mt-1">
                {{ $lastSyncFailed?->message ?? '-' }}
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    {{-- Filters --}}
    <div class="rounded-2xl border bg-white p-4 shadow-sm">
        <form class="grid grid-cols-1 md:grid-cols-5 gap-3" method="GET" action="{{ route('admin.jobs.index') }}">
            <div>
                <label class="text-xs text-slate-500">Status</label>
                <select name="status" class="mt-1 w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="pending" @selected($status==='pending')>Pending</option>
                    <option value="processing" @selected($status==='processing')>Processing</option>
                    <option value="all" @selected($status==='all')>All</option>
                </select>
            </div>

            <div>
                <label class="text-xs text-slate-500">Queue</label>
                <select name="queue" class="mt-1 w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach($queues as $qq)
                        <option value="{{ $qq }}" @selected($queue===$qq)>{{ $qq }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-xs text-slate-500">Keyword (job class)</label>
                <input name="q" value="{{ $q }}" class="mt-1 w-full rounded-xl border px-3 py-2 text-sm" placeholder="SyncUsers / Rebuild..." />
            </div>

            <div>
                <label class="text-xs text-slate-500">Older than (minutes)</label>
                <input type="number" name="older" value="{{ $olderMin }}" class="mt-1 w-full rounded-xl border px-3 py-2 text-sm" />
            </div>

            <div class="flex items-end gap-2">
                <button class="w-full rounded-xl bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800">
                    Filter
                </button>
                <a href="{{ route('admin.jobs.index') }}" class="rounded-xl border px-3 py-2 text-sm hover:bg-slate-50">
                    Reset
                </a>
            </div>
        </form>
    </div>

    {{-- Table --}}
    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
        <div class="p-4 border-b">
            <h2 class="font-semibold text-slate-800">Queue Jobs</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="text-left px-4 py-3">ID</th>
                        <th class="text-left px-4 py-3">Queue</th>
                        <th class="text-left px-4 py-3">Job</th>
                        <th class="text-left px-4 py-3">Attempts</th>
                        <th class="text-left px-4 py-3">Available At</th>
                        <th class="text-left px-4 py-3">Reserved At</th>
                        <th class="text-left px-4 py-3">Age</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($jobs as $job)
                        <tr class="border-t">
                            <td class="px-4 py-3 font-mono">{{ $job->id }}</td>
                            <td class="px-4 py-3">{{ $job->queue }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-900">{{ $job->job_name }}</div>
                            </td>
                            <td class="px-4 py-3">{{ $job->attempts }}</td>
                            <td class="px-4 py-3">{{ $job->available_at_h }}</td>
                            <td class="px-4 py-3">{{ $job->reserved_at_h ?? '-' }}</td>
                            <td class="px-4 py-3">{{ $job->age }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-slate-500">
                                Tidak ada job pada filter ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4">
            {{ $jobs->links() }}
        </div>
    </div>

    <div class="text-xs text-slate-500">
        Catatan: Jika pending menumpuk, biasanya worker belum jalan / stuck. Pastikan cron runner queue aktif di server.
    </div>

</div>
@endsection
