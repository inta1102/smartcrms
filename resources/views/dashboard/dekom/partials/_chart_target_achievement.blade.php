<div class="grid grid-cols-1 xl:grid-cols-3 gap-5">
    <div class="rounded-[28px] border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-200">
            <h3 class="text-xl font-extrabold text-slate-900">Pencairan vs Target</h3>
            <p class="mt-1 text-sm text-slate-500">Realisasi pencairan dibanding target.</p>
        </div>
        <div class="p-6">
            <canvas id="chartDisbursementVsTarget" height="140"></canvas>
        </div>
    </div>

    <div class="rounded-[28px] border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-200">
            <h3 class="text-xl font-extrabold text-slate-900">OS vs Target</h3>
            <p class="mt-1 text-sm text-slate-500">Outstanding aktual dibanding target.</p>
        </div>
        <div class="p-6">
            <canvas id="chartOsVsTarget" height="140"></canvas>
        </div>
    </div>

    <div class="rounded-[28px] border border-slate-200 bg-white shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b border-slate-200">
            <h3 class="text-xl font-extrabold text-slate-900">NPL vs Target</h3>
            <p class="mt-1 text-sm text-slate-500">NPL aktual dibanding target maksimum.</p>
        </div>
        <div class="p-6">
            <canvas id="chartNplVsTarget" height="140"></canvas>
        </div>
    </div>
</div>