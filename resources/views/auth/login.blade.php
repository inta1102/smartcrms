@extends('layouts.app')

@section('title', 'Login CRMS')

@section('content')
    <div class="w-full max-w-md">
        <div class="bg-white shadow-md rounded-2xl px-8 py-8 border border-slate-100">
            <h2 class="text-2xl font-semibold text-slate-800 mb-1 text-center">
                Login ke Smart-CRMS
            </h2>
            <p class="text-xs text-slate-500 mb-6 text-center">
                Gunakan <span class="font-semibold">nama</span> atau <span class="font-semibold">email</span> yang sama dengan SmartKPI.
            </p>

            @if ($errors->any())
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <ul class="list-disc list-inside">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('login.post') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Nama atau Email</label>
                    <input
                        type="text"
                        name="login"
                        value="{{ old('login') }}"
                        required
                        autofocus
                        class="block w-full rounded-lg border-slate-300 focus:border-msa-blue focus:ring-msa-blue text-sm px-3 py-2.5"
                        placeholder="contoh: inta / inta@example.com">
                </div>

                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
                    <input
                        type="password"
                        name="password"
                        required
                        class="block w-full rounded-lg border-slate-300 focus:border-msa-blue focus:ring-msa-blue text-sm px-3 py-2.5">
                </div>

                <button
                    type="submit"
                    class="w-full mt-3 inline-flex justify-center items-center px-4 py-2.5
                           text-sm font-medium rounded-lg
                           bg-msa-blue text-white
                           hover:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-msa-blue">
                    Masuk
                </button>
            </form>
        </div>

        <p class="mt-4 text-center text-xs text-slate-500">
            Jika lupa password, reset dari sistem SmartKPI seperti biasa.
        </p>
    </div>
@endsection
