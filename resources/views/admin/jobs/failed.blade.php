@extends('layouts.app')

@section('content')
<div class="mx-auto max-w-7xl p-4 space-y-4">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold text-slate-900">❌ Failed Jobs</h1>
            <p class="text-sm text-slate-500">Job yang gagal dan butuh tindakan (retry / delete).</p>
        </div>
        <a href="{{ route('admin.jobs.index') }}" class="rounded-xl border px-3 py-2 text-sm hover:bg-slate-50">
            Kembali ke Monitor
        </a>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-2xl border bg-white p-4 shadow-sm">
        <form class="grid grid-cols-1 md:grid-cols-4 gap-3" method="GET" action="{{ route('admin.jobs.failed') }}">
            <div>
                <label class="text-xs text-slate-500">Queue</label>
                <select name="queue" class="mt-1 w-full rounded-xl border px-3 py-2 text-sm">
                    <option value="">All</option>
                    @foreach($queues as $qq)
                        <option value="{{ $qq }}" @selected($queue===$qq)>{{ $qq }}</option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-2">
                <label class="text-xs text-slate-500">Keyword (job class)</label>
                <input name="q" value="{{ $q }}" class="mt-1 w-full rounded-xl border px-3 py-2 text-sm" placeholder="SyncUsers / WA Reminder..." />
            </div>

            <div class="flex items-end gap-2">
                <button class="w-full rounded-xl bg-slate-900 px-3 py-2 text-sm text-white hover:bg-slate-800">
                    Filter
                </button>
                <a href="{{ route('admin.jobs.failed') }}" class="rounded-xl border px-3 py-2 text-sm hover:bg-slate-50">
                    Reset
                </a>
            </div>
        </form>
    </div>

    <div class="rounded-2xl border bg-white shadow-sm overflow-hidden">
        <div class="p-4 border-b">
            <h2 class="font-semibold text-slate-800">Failed List</h2>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="text-left px-4 py-3">ID</th>
                        <th class="text-left px-4 py-3">Queue</th>
                        <th class="text-left px-4 py-3">Job</th>
                        <th class="text-left px-4 py-3">Failed At</th>
                        <th class="text-left px-4 py-3">Error</th>
                        <th class="text-left px-4 py-3">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($failed as $f)
                        <tr class="border-t align-top">
                            <td class="px-4 py-3 font-mono">{{ $f->id }}</td>
                            <td class="px-4 py-3">{{ $f->queue }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-900">{{ $f->job_name }}</div>
                            </td>
                            <td class="px-4 py-3">{{ $f->failed_at }}</td>
                            <td class="px-4 py-3">
                                <div class="text-slate-700">{{ $f->exception_short }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex gap-2">
                                    <form method="POST" action="{{ route('admin.jobs.failed.retry', $f->id) }}">
                                        @csrf
                                        <button class="rounded-xl border px-3 py-2 text-xs hover:bg-slate-50">
                                            Retry
                                        </button>
                                    </form>

                                    <form method="POST" action="{{ route('admin.jobs.failed.delete', $f->id) }}"
                                          onsubmit="return confirm('Hapus failed job #{{ $f->id }}?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700 hover:bg-rose-100">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-slate-500">
                                Tidak ada failed jobs.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-4">
            {{ $failed->links() }}
        </div>
    </div>

</div>
@endsection
