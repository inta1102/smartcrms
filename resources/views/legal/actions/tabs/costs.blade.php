@php $total = $action->costs->sum('amount'); @endphp

<div class="rounded-xl border border-slate-200 bg-white p-4">
    <div class="mb-3 flex items-center justify-between gap-3">
        <h3 class="text-sm font-semibold text-slate-800">Biaya</h3>

        <div class="text-sm font-semibold text-slate-800">
            Total: <span class="text-indigo-600">Rp {{ number_format((int)$total, 0, ',', '.') }}</span>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-600">
                <tr>
                    <th class="px-3 py-2">Deskripsi</th>
                    <th class="px-3 py-2">Tanggal</th>
                    <th class="px-3 py-2 text-right">Nominal</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($action->costs as $c)
                    <tr class="hover:bg-slate-50">
                        <td class="px-3 py-2 font-semibold text-slate-800">{{ $c->description ?? '-' }}</td>
                        <td class="px-3 py-2 text-slate-600">{{ optional($c->cost_date ?? $c->created_at)->format('d M Y') }}</td>
                        <td class="px-3 py-2 text-right font-semibold text-slate-800">
                            Rp {{ number_format((int)($c->amount ?? 0), 0, ',', '.') }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-3 py-6 text-center text-sm text-slate-500">
                            Belum ada biaya.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
