@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-7xl p-4 space-y-4">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-slate-900">⚙️ Job Monitor</h1>
            <p class="text-sm text-slate-500">Pantau antrian job (database queue) & kesehatan proses update data.</p>
        </div>

        <div class="flex gap-2">
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
        Catatan: Jika pending menumpuk, biasanya worker belum jalan / stuck. Pastikan <code>php artisan queue:work</code> aktif di server (Supervisor).
    </div>

</div>
@endsection
