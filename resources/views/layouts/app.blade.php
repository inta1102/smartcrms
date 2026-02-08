<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'SmartCRMS')</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        msa: { blue: '#003f7d', light: '#e5f1ff' },
                    },
                },
            },
        }
    </script>

    <style>[x-cloak]{ display:none !important; }</style>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="min-h-screen bg-slate-100">

<header class="bg-msa-blue text-white shadow">
    <div class="max-w-7xl mx-auto px-3 sm:px-4 py-3 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            @auth
                <button type="button"
                    class="sm:hidden inline-flex items-center justify-center rounded-lg border border-white/30 px-2.5 py-1.5 hover:bg-white/10"
                    x-data @click="$dispatch('toggle-sidebar')"
                    aria-label="Buka menu">☰</button>
            @endauth

            <div class="flex flex-col leading-tight">
                <span class="font-semibold text-lg tracking-wide">Smart-CRMS</span>
                <span class="text-[10px] bg-white/10 px-2 py-0.5 rounded-full w-fit">
                    Credit Recovery Monitoring
                </span>
            </div>
        </div>

        @auth
            <div class="flex items-center gap-2 text-xs sm:text-sm sm:justify-end">
                <span class="truncate max-w-[180px] sm:max-w-none">
                    Hi, <span class="font-semibold">{{ auth()->user()->name }}</span>
                </span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                        class="px-3 py-1 rounded-md border border-white/40 text-[11px] hover:bg-white hover:text-msa-blue transition">
                        Logout
                    </button>
                </form>
            </div>
        @endauth
    </div>
</header>


<div class="max-w-7xl mx-auto px-3 sm:px-4 py-4">
    @auth
        <div class="flex gap-4 min-w-0 overflow-x-hidden">
            @include('layouts.partials.sidebar')

            <main class="flex-1 min-w-0 overflow-x-hidden">
                <div class="rounded-2xl border border-slate-200 bg-white shadow-sm p-4 sm:p-6 min-w-0 overflow-x-hidden">
                    @yield('content')
                </div>
            </main>
        </div>
    @else
        <main class="flex items-start sm:items-center justify-center py-6 min-w-0 overflow-x-hidden">
            @yield('content')
        </main>
    @endauth
</div>


<footer class="py-3 text-center text-xs text-slate-500">
    &copy; {{ date('Y') }} BPR Madani Sejahtera Abadi • Smart-CRMS
</footer>

@livewireScripts
@stack('scripts')
</body>
</html>
