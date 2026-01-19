@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto p-4">
  <h1 class="text-xl font-bold">Ajukan Non-Litigasi (Draft)</h1>

  @if(session('error')) <div class="mt-3 p-3 bg-red-50 text-red-700 rounded">{{ session('error') }}</div> @endif
  @if(session('success')) <div class="mt-3 p-3 bg-green-50 text-green-700 rounded">{{ session('success') }}</div> @endif

  <form class="mt-4 bg-white p-4 rounded shadow"
        method="POST"
        action="{{ route('cases.nonlit.store', $case) }}">
    @include('nonlit._form', ['types' => $types])
    <div class="mt-4 flex gap-2">
      <button class="px-4 py-2 rounded bg-blue-600 text-white">Simpan Draft</button>
      <a href="{{ route('cases.nonlit.index', $case) }}" class="px-4 py-2 rounded border">Kembali</a>
    </div>
  </form>
</div>
@endsection
