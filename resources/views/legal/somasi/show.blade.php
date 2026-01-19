@extends('layouts.app')

@section('content')
@php
    $caseNo = $action->legalCase?->legal_case_no ?? '-';
    $debtor = $action->legalCase?->debtor_name ?? $action->legalCase?->nplCase?->loanAccount?->customer_name ?? '-';
    $state  = $progress['state'] ?? 'draft';

    $stepClass = fn($on) => $on ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-600';
@endphp

<div class="max-w-6xl mx-auto px-4 py-6">

    <div class="mb-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-bold text-slate-900">Somasi</h1>
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold ring-1 bg-slate-50 text-slate-700 ring-slate-200">
                        {{ strtoupper($state) }}
                    </span>
                </div>
                <div class="mt-2 text-sm text-slate-600 flex flex-wrap gap-x-6 gap-y-1">
                    <div><span class="text-slate-500">Kasus:</span> <span class="font-semibold text-slate-800">{{ $caseNo }}</span></div>
                    <div><span class="text-slate-500">Debitur:</span> <span class="font-semibold text-slate-800">{{ $debtor }}</span></div>
                    <div><span class="text-slate-500">External Ref:</span> <span class="font-semibold text-slate-800">{{ $action->external_ref_no ?? '-' }}</span></div>
                </div>
            </div>

            <div class="flex gap-2">
                <a href="{{ route('legal-actions.show', $action) }}?tab=overview"
                   class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                    ← Kembali
                </a>
            </div>
        </div>

        {{-- Progress bar --}}
        <div class="mt-4 grid grid-cols-4 gap-2 text-xs font-semibold">
            <div class="rounded-xl px-3 py-2 text-center {{ $stepClass(in_array($state, ['draft','sent','waiting','responded','no_response'], true)) }}">Draft</div>
            <div class="rounded-xl px-3 py-2 text-center {{ $stepClass(in_array($state, ['sent','waiting','responded','no_response'], true)) }}">Dikirim</div>
            <div class="rounded-xl px-3 py-2 text-center {{ $stepClass(in_array($state, ['waiting','responded','no_response'], true)) }}">Menunggu</div>
            <div class="rounded-xl px-3 py-2 text-center {{ $stepClass(in_array($state, ['responded','no_response'], true)) }}">Selesai</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {{-- Kartu Status --}}
        <div class="lg:col-span-2 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-bold text-slate-800">Status Somasi</h2>

            <div class="mt-3 space-y-2 text-sm text-slate-700">
                <div class="flex justify-between">
                    <span class="text-slate-500">Tanggal Kirim</span>
                    <span class="font-semibold">{{ $progress['sent_at']?->format('d M Y H:i') ?? '-' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Somasi Diterima</span>
                    <span class="font-semibold">{{ $progress['received_at']?->format('d M Y H:i') ?? '-' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Deadline Respons</span>
                    <span class="font-semibold">
                        {{ $progress['deadline_at']?->format('d M Y H:i') ?? '-' }}
                        @if(!empty($progress['deadline_status']))
                            <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-slate-100">{{ $progress['deadline_status'] }}</span>
                        @endif
                    </span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">Respons Debitur</span>
                    <span class="font-semibold">{{ $progress['response_at']?->format('d M Y H:i') ?? '-' }}</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-slate-500">No Response</span>
                    <span class="font-semibold">{{ $progress['no_response_at']?->format('d M Y H:i') ?? '-' }}</span>
                </div>
            </div>

            {{-- Tombol aksi kontekstual --}}
            <div class="mt-5 flex flex-wrap gap-2">
                @can('update', $action)
                    {{-- Mark Sent --}}
                    <form method="POST" action="{{ route('legal-actions.somasi.markSent', $action) }}" class="flex flex-wrap gap-2 items-end">
                        @csrf
                        <div>
                            <label class="block text-xs font-semibold text-slate-600">Tanggal Kirim</label>
                            <input type="datetime-local" name="sent_at" value="{{ now()->format('Y-m-d\TH:i') }}"
                                   class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600">Deadline (hari)</label>
                            <input type="number" name="deadline_days" value="14"
                                   class="mt-1 w-28 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        </div>
                        <button class="rounded-xl bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">
                            ✔ Tandai Dikirim
                        </button>
                    </form>
                     {{-- Mark Diterima --}}
                    <form method="POST" action="{{ route('legal-actions.somasi.markReceived', $action) }}" class="flex flex-wrap gap-2 items-end">
                        @csrf
                        <div>
                            <label class="block text-xs font-semibold text-slate-600">Tanggal Diterima</label>
                            <input type="datetime-local" name="received_at" value="{{ now()->format('Y-m-d\TH:i') }}"
                                class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600">Channel</label>
                            <select name="delivery_channel" class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                <option value="">-</option>
                                <option value="pos">POS</option>
                                <option value="kurir">Kurir</option>
                                <option value="petugas_bank">Petugas Bank</option>
                                <option value="kuasa_hukum">Kuasa Hukum</option>
                                <option value="lainnya">Lainnya</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600">No Resi</label>
                            <input type="text" name="receipt_no" placeholder="opsional"
                                class="mt-1 w-40 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600">Diterima oleh</label>
                            <input type="text" name="received_by_name" placeholder="nama penerima"
                                class="mt-1 w-44 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        </div>

                        <button class="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-700">
                            📩 Diterima
                        </button>
                    </form>

                    {{-- Mark Response --}}
                    <form method="POST" action="{{ route('legal-actions.somasi.markResponse', $action) }}" class="flex flex-wrap gap-2 items-end">
                        @csrf
                        <div>
                            <label class="block text-xs font-semibold text-slate-600">Tanggal Respons</label>
                            <input type="datetime-local" name="response_at" value="{{ now()->format('Y-m-d\TH:i') }}"
                                   class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600">Channel</label>
                            <select name="response_channel" class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                                <option value="">-</option>
                                <option value="datang">Datang</option>
                                <option value="surat">Surat</option>
                                <option value="wa">WA</option>
                                <option value="telepon">Telepon</option>
                                <option value="kuasa_hukum">Kuasa Hukum</option>
                                <option value="lainnya">Lainnya</option>
                            </select>
                        </div>
                        <button class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-700">
                            ✅ Respons
                        </button>
                    </form>

                    {{-- Mark No Response --}}
                    <form method="POST" action="{{ route('legal-actions.somasi.markNoResponse', $action) }}" class="flex flex-wrap gap-2 items-end">
                        @csrf
                        <div>
                            <label class="block text-xs font-semibold text-slate-600">Dicek pada</label>
                            <input type="datetime-local" name="checked_at" value="{{ now()->format('Y-m-d\TH:i') }}"
                                   class="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm">
                        </div>
                        <button class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700">
                            ❌ No Response
                        </button>
                    </form>
                @endcan
            </div>
        </div>

        {{-- Dokumen --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-sm font-bold text-slate-800">Dokumen Somasi</h2>

            <div class="mt-3 space-y-2 text-sm">
                <div class="flex items-center justify-between">
                    <span>Draft</span>
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $progress['has_draft'] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                        {{ $progress['has_draft'] ? 'ADA' : 'BELUM' }}
                    </span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Surat Final (Signed)</span>
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $progress['has_signed'] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                        {{ $progress['has_signed'] ? 'ADA' : 'BELUM' }}
                    </span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Bukti Kirim</span>
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $progress['has_sent_proof'] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                        {{ $progress['has_sent_proof'] ? 'ADA' : 'BELUM' }}
                    </span>
                </div>
                <div class="flex items-center justify-between">
                    <span>Bukti Terima</span>
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $progress['has_received_proof'] ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                        {{ $progress['has_received_proof'] ? 'ADA' : 'BELUM' }}
                    </span>
                </div>
            </div>

            @can('update', $action)
            <form method="POST" action="{{ route('legal-actions.somasi.uploadDocument', $action) }}" enctype="multipart/form-data" class="mt-4 space-y-2">
                @csrf
                <select name="doc_type" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm">
                    <option value="somasi_draft">Draft Somasi</option>
                    <option value="somasi_signed">Somasi Signed</option>
                    <option value="somasi_sent_proof">Bukti Kirim</option>
                    <option value="somasi_received_proof">Bukti Terima</option>
                </select>
                <input type="text" name="title" placeholder="Judul dokumen"
                       class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" required>
                <input type="file" name="file" class="w-full text-sm" required>
                <button class="w-full rounded-xl bg-slate-900 px-3 py-2 text-sm font-semibold text-white">
                    Upload Dokumen
                </button>
            </form>
            @endcan

            {{-- list dokumen --}}
            <div class="mt-4">
                <h3 class="text-xs font-semibold text-slate-500 mb-2">Daftar Upload</h3>
                <div class="space-y-2">
                    @forelse($action->documents as $doc)
                        <div class="rounded-xl border border-slate-100 p-3 text-sm">
                            <div class="font-semibold text-slate-800">{{ $doc->title }}</div>
                            <div class="text-xs text-slate-500">{{ $doc->doc_type }} • {{ $doc->uploaded_at?->format('d M Y H:i') }}</div>
                            <a class="text-xs font-semibold text-indigo-700"
                               href="{{ asset('storage/'.$doc->file_path) }}" target="_blank">Buka</a>
                        </div>
                    @empty
                        <div class="text-xs text-slate-400">Belum ada dokumen.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Events list --}}
    <div class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-sm font-bold text-slate-800 mb-3">Events Somasi</h2>

        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="bg-slate-50 border-b border-slate-100">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Tipe</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Judul</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Jadwal</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Reminder</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($action->events as $ev)
                        <tr class="border-b last:border-b-0 border-slate-100">
                            <td class="px-3 py-2">
                                <span class="px-2 py-0.5 rounded-lg bg-slate-100 font-semibold">{{ $ev->event_type }}</span>
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                <div class="font-semibold">{{ $ev->title }}</div>
                                @if($ev->notes)
                                    <div class="text-[11px] text-slate-500 whitespace-pre-line">{{ $ev->notes }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-700 whitespace-nowrap">
                                {{ $ev->event_at?->format('d M Y H:i') ?? '-' }}
                            </td>
                            <td class="px-3 py-2 text-slate-700 whitespace-nowrap">
                                {{ $ev->remind_at?->format('d M Y H:i') ?? '-' }}
                                @if($ev->reminded_at)
                                    <div class="text-[11px] text-slate-400">Terkirim: {{ $ev->reminded_at->format('d M Y H:i') }}</div>
                                @else
                                    <div class="text-[11px] text-slate-400">belum terkirim</div>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <span class="px-2 py-0.5 rounded-full text-[11px]
                                    {{ $ev->status === 'done' ? 'bg-emerald-50 text-emerald-700' : ($ev->status === 'cancelled' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-700') }}">
                                    {{ $ev->status }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    </div>

</div>
@endsection
