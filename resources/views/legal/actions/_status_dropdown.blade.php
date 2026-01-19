@php
    $current = $action->status;
@endphp

@can('updateStatus', $action)
<div class="inline-flex items-center gap-2">
    <span class="px-2 py-1 rounded bg-slate-100 text-slate-700 text-xs font-semibold">
        Status: {{ strtoupper($current) }}
    </span>

    <div class="relative" x-data="{open:false}">
        <button type="button"
                class="px-3 py-1.5 rounded border text-xs font-semibold hover:bg-slate-50"
                @click="open = !open"
                title="Ubah status (hanya yang valid)">
            Ubah Status ▾
        </button>

        <div x-show="open" @click.outside="open=false"
             class="absolute z-50 mt-2 w-48 rounded border bg-white shadow">
            @forelse($allowed as $to)
                <form method="POST" action="{{ route('legal-actions.update-status', $action) }}">
                    @csrf
                    <input type="hidden" name="to_status" value="{{ $to }}">
                    <input type="hidden" name="remarks" value="">
                    <button type="submit"
                            class="w-full text-left px-3 py-2 text-xs hover:bg-slate-100"
                            title="Pindah dari {{ $current }} ke {{ $to }}">
                        → {{ strtoupper($to) }}
                    </button>
                </form>
            @empty
                <div class="px-3 py-2 text-xs text-slate-500">
                    Tidak ada transisi yang tersedia
                </div>
            @endforelse
        </div>
    </div>
</div>
@endcan
