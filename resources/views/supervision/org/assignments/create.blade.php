@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">
    <div class="mb-4">
        <h1 class="text-lg font-bold text-slate-900">Tambah Org Assignment</h1>
        <p class="text-sm text-slate-500">
            Admin Master – struktur supervisi berdasarkan unit (pengawas vs bisnis/operasional).
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

    <form method="POST" action="{{ route('supervision.org.assignments.store') }}"
          class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        @csrf

       @include('supervision.org.assignments._form', [
            'mode' => 'create',
            'assignment' => null,
        ])

        <div class="mt-6 flex items-center justify-between">
            <a href="{{ route('supervision.org.assignments.index') }}"
               class="inline-flex items-center rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                Kembali
            </a>

            <button type="submit"
                    class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                Simpan
            </button>
        </div>
    </form>
</div>
@endsection
