@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="mb-4">
        <h1 class="text-lg font-bold text-slate-900">Edit Org Assignment</h1>
        <p class="text-sm text-slate-500">
            Update hubungan struktural untuk unit terkait. Untuk mutasi staff, lebih aman gunakan record baru.
        </p>
    </div>

    @if ($errors->any())
        <div class="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800">
            <div class="font-semibold">Ada error input:</div>
            <ul class="mt-1 list-disc pl-5 text-sm">
                @foreach ($errors->all() as $e)
                    <li>{{ $e }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- FORM UPDATE --}}
    <form method="POST" action="{{ route('supervision.org.assignments.update', $assignment) }}">
        @csrf
        @method('PUT')

        @include('supervision.org.assignments._form', [
            'mode' => 'edit',
            'assignment' => $assignment,
        ])

        <div class="mt-6 flex items-center justify-between">
            <a href="{{ route('supervision.org.assignments.index') }}"
               class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Kembali
            </a>

            <button type="submit"
                    class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                Update
            </button>
        </div>
    </form>

    {{-- AKSI AKHIRI (audit-friendly) --}}
    <form method="POST"
          action="{{ route('supervision.org.assignments.end', $assignment) }}"
          class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-5">
        @csrf

        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <div class="font-semibold text-rose-900">Akhiri Assignment</div>
                <div class="text-sm text-rose-700">
                    Menonaktifkan assignment ini dan mengisi effective_to hari ini (audit trail).
                </div>
            </div>

            <button type="submit"
                    onclick="return confirm('Yakin ingin mengakhiri assignment ini?')"
                    class="inline-flex items-center rounded-lg bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                Akhiri
            </button>
        </div>
    </form>
</div>
@endsection
