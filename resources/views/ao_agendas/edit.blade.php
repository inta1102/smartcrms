@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto px-4 py-6">

    <div class="rounded-2xl border bg-white p-5 shadow-sm">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-lg font-bold text-slate-900">Edit Agenda AO</h1>
                <p class="text-sm text-slate-500">Update jadwal, status, hasil, dan evidence.</p>
            </div>
            <span class="rounded-full border px-3 py-1 text-xs font-semibold
                {{ $agenda->status === 'done' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-50 text-slate-700 border-slate-200' }}">
                {{ strtoupper($agenda->status) }}
            </span>
        </div>

        @if(session('success'))
            <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 text-sm">
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800 text-sm">
                <ul class="list-disc pl-5">
                    @foreach($errors->all() as $e) <li>{{ $e }}</li> @endforeach
                </ul>
            </div>
        @endif

        {{-- ACTION BAR --}}
        <div class="mt-4 flex flex-wrap items-center gap-2">
            {{-- Start --}}
            @can('start', $agenda)
                @if(!in_array($agenda->status, ['done','cancelled']))
                    <form method="POST" action="{{ route('ao-agendas.start', $agenda) }}">
                        @csrf
                        <button type="submit"
                            class="rounded-xl border px-4 py-2 text-sm font-semibold hover:bg-slate-50">
                            ▶️ Mulai
                        </button>
                    </form>
                @endif
            @endcan

            {{-- Complete --}}
            @can('complete', $agenda)     
                @if(!in_array($agenda->status, ['done','cancelled']))
                    <button type="button"
                        onclick="document.getElementById('modalComplete').showModal()"
                        class="rounded-xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold hover:bg-emerald-700">
                        ✅ Selesai
                    </button>
                @endif
            @endcan

            {{-- Reschedule --}}
            @can('reschedule', $agenda)
                @if($agenda->status !== 'done')
                    <button type="button"
                        onclick="document.getElementById('modalReschedule').showModal()"
                        class="rounded-xl border border-indigo-200 text-indigo-700 px-4 py-2 text-sm font-semibold hover:bg-indigo-50">
                        🗓️ Reschedule
                    </button>
                @endif
            @endcan

            {{-- Cancel --}}
            @can('cancel', $agenda)    
                @if($agenda->status !== 'done')
                    <button type="button"
                        onclick="document.getElementById('modalCancel').showModal()"
                        class="rounded-xl border border-rose-200 text-rose-700 px-4 py-2 text-sm font-semibold hover:bg-rose-50">
                        ✖ Batalkan
                    </button>
                @endif
            @endcan

            <div>
                <label class="text-xs font-semibold text-slate-600">Status</label>
                <div class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800">
                    {{ strtoupper($agenda->status) }}
                </div>
                <p class="mt-1 text-[11px] text-slate-500">Status mengikuti log tindakan (otomatis).</p>
            </div>

        </div>

        {{-- MODAL: COMPLETE --}}
        <dialog id="modalComplete" class="rounded-2xl p-0 shadow-xl backdrop:bg-black/30">
            <form method="POST" action="{{ route('ao-agendas.complete', $agenda) }}" class="w-[min(560px,92vw)] bg-white p-5">
                @csrf
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-bold text-slate-900">Selesaikan Agenda</div>
                        <div class="text-sm text-slate-500">Isi ringkas hasil (opsional). Evidence upload tetap di form utama.</div>
                    </div>
                    <button type="button" onclick="document.getElementById('modalComplete').close()"
                            class="rounded-lg border px-2 py-1 text-sm">Tutup</button>
                </div>

                <div class="mt-4 space-y-3">
                    <div>
                        <label class="text-xs font-semibold text-slate-600">Result Summary</label>
                        <textarea name="result_summary" rows="2" class="mt-1 w-full rounded-xl border-slate-200">{{ old('result_summary', $agenda->result_summary) }}</textarea>
                    </div>

                    <div>
                        <label class="text-xs font-semibold text-slate-600">Result Detail</label>
                        <textarea name="result_detail" rows="4" class="mt-1 w-full rounded-xl border-slate-200">{{ old('result_detail', $agenda->result_detail) }}</textarea>
                    </div>

                    @if($agenda->evidence_required)
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800 text-sm">
                            Agenda ini <b>wajib evidence</b>. Pastikan upload file / isi evidence notes di form utama sebelum klik Selesai.
                        </div>
                    @endif
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" onclick="document.getElementById('modalComplete').close()"
                            class="rounded-xl border px-4 py-2 text-sm">Batal</button>
                    <button type="submit"
                            class="rounded-xl bg-emerald-600 text-white px-4 py-2 text-sm font-semibold hover:bg-emerald-700">
                        Konfirmasi Selesai
                    </button>
                </div>
            </form>
        </dialog>

        {{-- MODAL: RESCHEDULE --}}
        <dialog id="modalReschedule" class="rounded-2xl p-0 shadow-xl backdrop:bg-black/30">
            <form method="POST" action="{{ route('ao-agendas.reschedule', $agenda) }}" class="w-[min(560px,92vw)] bg-white p-5">
                @csrf
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-bold text-slate-900">Reschedule Agenda</div>
                        <div class="text-sm text-slate-500">Wajib isi tanggal + alasan (audit).</div>
                    </div>
                    <button type="button" onclick="document.getElementById('modalReschedule').close()"
                            class="rounded-lg border px-2 py-1 text-sm">Tutup</button>
                </div>

                <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-slate-600">Due At Baru</label>
                        <input type="datetime-local" name="due_at"
                            value="{{ old('due_at') }}"
                            class="mt-1 w-full rounded-xl border-slate-200">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600">Alasan</label>
                        <input type="text" name="reason" value="{{ old('reason') }}"
                            class="mt-1 w-full rounded-xl border-slate-200" placeholder="contoh: Debitur minta jadwal ulang">
                    </div>
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" onclick="document.getElementById('modalReschedule').close()"
                            class="rounded-xl border px-4 py-2 text-sm">Batal</button>
                    <button type="submit"
                            class="rounded-xl bg-indigo-600 text-white px-4 py-2 text-sm font-semibold hover:bg-indigo-700">
                        Simpan Reschedule
                    </button>
                </div>
            </form>
        </dialog>

        {{-- MODAL: CANCEL --}}
        <dialog id="modalCancel" class="rounded-2xl p-0 shadow-xl backdrop:bg-black/30">
            <form method="POST" action="{{ route('ao-agendas.cancel', $agenda) }}" class="w-[min(560px,92vw)] bg-white p-5">
                @csrf
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-bold text-slate-900">Batalkan Agenda</div>
                        <div class="text-sm text-slate-500">Wajib isi alasan (audit).</div>
                    </div>
                    <button type="button" onclick="document.getElementById('modalCancel').close()"
                            class="rounded-lg border px-2 py-1 text-sm">Tutup</button>
                </div>

                <div class="mt-4">
                    <label class="text-xs font-semibold text-slate-600">Alasan Pembatalan</label>
                    <input type="text" name="reason" value="{{ old('reason') }}"
                        class="mt-1 w-full rounded-xl border-slate-200" placeholder="contoh: Target berubah, agenda diganti">
                </div>

                <div class="mt-5 flex items-center justify-end gap-2">
                    <button type="button" onclick="document.getElementById('modalCancel').close()"
                            class="rounded-xl border px-4 py-2 text-sm">Batal</button>
                    <button type="submit"
                            class="rounded-xl bg-rose-600 text-white px-4 py-2 text-sm font-semibold hover:bg-rose-700">
                        Konfirmasi Batalkan
                    </button>
                </div>
            </form>
        </dialog>

        <form method="POST" action="{{ route('ao-agendas.update', $agenda) }}" enctype="multipart/form-data" class="mt-5 space-y-4">
            @csrf
            @method('PUT')

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="text-xs font-semibold text-slate-600">Planned At</label>
                    <input type="datetime-local" name="planned_at"
                        value="{{ old('planned_at', optional($agenda->planned_at)->format('Y-m-d\TH:i')) }}"
                        class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm
                                focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 focus:outline-none">
                </div>
                <div>
                    <label class="text-xs font-semibold text-slate-600">Due At</label>
                    <input type="datetime-local" name="due_at"
                           value="{{ old('due_at', optional($agenda->due_at)->format('Y-m-d\TH:i')) }}"
                           class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm
                                focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 focus:outline-none">
                </div>
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-600">Status</label>
                <select name="status" class="mt-1 w-full rounded-xl border-slate-200">
                    @foreach(['planned','in_progress','done','overdue','cancelled'] as $st)
                        <option value="{{ $st }}" @selected(old('status', $agenda->status) === $st)>
                            {{ strtoupper($st) }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-xs font-semibold text-slate-600">
                    Notes
                </label>
                <textarea
                    name="notes"
                    rows="3"
                    placeholder="Catatan target / arahan singkat"
                    class="mt-1 w-full rounded-xl
                        border border-slate-300
                        bg-white
                        px-3 py-2
                        text-sm
                        focus:border-indigo-500
                        focus:ring-2 focus:ring-indigo-100
                        focus:outline-none"
                >{{ old('notes', $agenda->notes) }}</textarea>
            </div>

            @php
                $lastAction = \App\Models\CaseAction::where('ao_agenda_id', $agenda->id)
                    ->latest('action_at')
                    ->first();
            @endphp

            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div class="text-xs font-semibold text-slate-700">Aktivitas Terakhir (otomatis dari log)</div>

                @if($lastAction)
                    <div class="mt-2 text-xs text-slate-600">
                        <div><span class="font-semibold">Waktu:</span> {{ $lastAction->action_at }}</div>
                        <div><span class="font-semibold">Jenis:</span> {{ strtoupper($lastAction->action_type) }}</div>
                        <div><span class="font-semibold">Hasil:</span> {{ $lastAction->result ?? '-' }}</div>
                        <div class="mt-2 whitespace-pre-line text-slate-700">{{ $lastAction->description ?? '-' }}</div>
                    </div>
                @else
                    <div class="mt-2 text-xs text-slate-500">Belum ada log tindakan untuk agenda ini.</div>
                @endif
            </div>


            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                <div class="text-sm font-semibold text-slate-800">Evidence</div>
                <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-semibold text-slate-600">Upload File (jpg/png/pdf)</label>
                        <input type="file" name="evidence_file" class="mt-1 w-full rounded-xl border-slate-200 bg-white">
                        @if($agenda->evidence_path)
                            <div class="mt-2 text-xs text-slate-600">
                                Saat ini: <span class="font-semibold">{{ $agenda->evidence_path }}</span>
                            </div>
                        @endif
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600">Evidence Notes</label>
                        <input type="text" name="evidence_notes"
                            value="{{ old('evidence_notes', $agenda->evidence_notes) }}"
                            class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm
                                    focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 focus:outline-none">

                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-2">
                <a href="{{ url()->previous() }}" class="rounded-xl border px-4 py-2 text-sm">Kembali</a>
                <button class="rounded-xl bg-slate-900 text-white px-4 py-2 text-sm font-semibold">
                    Simpan
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
