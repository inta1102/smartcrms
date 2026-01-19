@extends('layouts.app')

@section('content')
<div class="flex items-center justify-center min-h-[60vh]">
    <div class="text-center max-w-md">
        <div class="text-5xl mb-4">🚫</div>
        <h1 class="text-xl font-bold text-slate-900">
            Akses Dibatasi
        </h1>
        <p class="mt-2 text-slate-600 text-sm">
            Anda tidak memiliki hak akses untuk menu atau halaman ini.
        </p>

        <a href="{{ route('dashboard') }}"
           class="inline-block mt-5 rounded-lg bg-slate-900 px-4 py-2 text-white text-sm">
            Kembali ke Dashboard
        </a>
    </div>
</div>
@endsection
