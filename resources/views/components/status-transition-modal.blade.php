@props([
    'actionId',
    'actionType' => null,
    'route',
])

<div
    x-data="statusModal()"
    x-on:open-status-modal.window="
        openModal(
            $event.detail.toStatus,
            $event.detail.label,
            $event.detail.hint,
            $event.detail.redirectTab,
            $event.detail.btnClass
        )
    "
>
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center px-4">
        <div class="absolute inset-0 bg-slate-900/50" @click="open=false"></div>

        <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-xl border border-slate-200 p-5">
            <div class="flex items-start justify-between">
                <div>
                    <div class="text-sm font-semibold text-slate-900" x-text="label"></div>
                    <div class="mt-1 text-xs text-slate-500">
                        Status baru:
                        <span class="font-semibold uppercase" x-text="toStatus"></span>
                    </div>
                </div>
                <button @click="open=false"
                        class="rounded-lg border border-slate-200 px-2 py-1 text-sm hover:bg-slate-50">
                    ✕
                </button>
            </div>

            <template x-if="hint">
                <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800"
                     x-text="hint"></div>
            </template>

            <div class="mt-4">
                <label class="text-xs text-slate-500">Remarks</label>
                <textarea x-model="remarks" rows="4"
                          class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500"
                          placeholder="Alasan / catatan perubahan status (opsional)"></textarea>
            </div>

            <form :action="route" method="POST" class="mt-4 flex justify-end gap-2">
                @csrf
                {{-- @method('PUT') jika perlu --}}
                <input type="hidden" name="to_status" :value="toStatus">
                <input type="hidden" name="remarks" :value="remarks">
                <input type="hidden" name="redirect_tab" :value="redirectTab">

                <button type="button"
                        class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
                        @click="open=false">
                    Batal
                </button>

                <button type="submit"
                        class="rounded-xl px-4 py-2 text-sm font-semibold text-white"
                        :class="btnClass">
                    Kirim
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function statusModal(){
    return {
        open:false,
        toStatus:'',
        label:'',
        hint:'',
        remarks:'',
        redirectTab:'',
        btnClass:'',
        route: @json($route),

        openModal(toStatus,label,hint,redirectTab,btnClass){
            this.toStatus = toStatus;
            this.label = label;
            this.hint = hint || '';
            this.redirectTab = redirectTab || '';
            this.btnClass = btnClass || 'bg-indigo-600';
            this.remarks = '';
            this.open = true;
        }
    }
}
</script>
