@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto p-4">
  <h1 class="text-xl font-bold">Edit Draft Non-Litigasi</h1>

  <form class="mt-4 bg-white p-4 rounded shadow"
        method="POST"
        action="{{ route('nonlit.update', $nonLit) }}">
    @include('nonlit._form', ['types' => $types, 'nonLit' => $nonLit, 'method' => 'PUT'])
    <div class="mt-4 flex gap-2">
      <button class="px-4 py-2 rounded bg-blue-600 text-white">Update Draft</button>
      <a href="{{ route('nonlit.show', $nonLit) }}" class="px-4 py-2 rounded border">Batal</a>
    </div>
  </form>
</div>
@endsection
