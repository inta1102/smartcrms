@php
  $documents = $documents ?? $action->htDocuments->sortByDesc('created_at');
@endphp

<div class="rounded-2xl border bg-white p-5 shadow-sm">
    <div class="text-lg font-bold">Dokumen</div>
    <div class="text-sm text-slate-500">
        Upload dan verifikasi dokumen pendukung pendaftaran lelang.
    </div>

    <div class="mt-4 overflow-x-auto rounded-xl border">
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-slate-600">
                <tr>
                    <th class="px-4 py-3 text-left font-semibold">Doc Type</th>
                    <th class="px-4 py-3 text-left font-semibold">No / Tgl</th>
                    <th class="px-4 py-3 text-left font-semibold">Required</th>
                    <th class="px-4 py-3 text-left font-semibold">Status</th>
                    <th class="px-4 py-3 text-left font-semibold">File</th>
                    <th class="px-4 py-3 text-right font-semibold">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse(($documents ?? []) as $doc)
                    <tr>
                        <td class="px-4 py-3">
                            <div class="font-semibold">{{ $doc->doc_type ?? '-' }}</div>
                            <div class="text-xs text-slate-500">{{ $doc->remarks ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div>{{ $doc->doc_no ?? '-' }}</div>
                            <div class="text-xs text-slate-500">{{ optional($doc->doc_date)->format('d/m/Y') }}</div>
                        </td>
                        <td class="px-4 py-3">
                            @if(($doc->is_required ?? false))
                                <span class="rounded-full border border-amber-300 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700">YES</span>
                            @else
                                <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-600">NO</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs font-semibold text-indigo-700">
                                {{ strtoupper($doc->status ?? 'uploaded') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            @if(!empty($doc->file_path))
                                <a
                                    href="{{ route('legal-actions.ht.documents.view', [$action, $doc]) }}"
                                    target="_blank" rel="noopener"
                                    @click.stop
                                    class="text-indigo-600 hover:underline"
                                    >
                                    Lihat
                                </a>

                            @else
                                <span class="text-slate-400">-</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            {{-- aksi upload/replace/delete nanti kita sambung ke route yang sudah kamu punya --}}
                            <span class="text-slate-400 text-xs">…</span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-4 text-slate-500">
                            Belum ada dokumen.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
