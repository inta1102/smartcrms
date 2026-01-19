@php
    $user = auth()->user();

    // normalisasi level lama (string) / enum
    $level = $user?->level;
    $userLevel = strtolower($level instanceof \App\Enums\UserRole ? $level->value : (string) $level);

    $checklist = $checklist ?? collect();

    // ✅ siapa yang boleh checklist:
    // - TL (TL/TLL/TLR/TLF)
    // - KASI (KSR/KSL) untuk kasus seperti Helmi (tanpa TL)
    // NOTE: final auth tetap di controller/policy, ini hanya untuk tampilkan UI.
    $canChecklist = $user && (
        (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['TL','TLL','TLR','TLF','KSR','KSL']))
        || in_array($userLevel, ['tl','ksr','ksl'], true)
    );
@endphp

<div class="mt-6">

    {{-- ============================= --}}
    {{-- Checklist Administratif       --}}
    {{-- ============================= --}}

    @if($canChecklist)

        <form method="POST"
              action="{{ route('legal-actions.checklists.save', $action) }}"
              class="space-y-4">
            @csrf

            <div class="rounded-2xl border bg-white p-5 shadow-sm">

                <div class="mb-1 text-lg font-bold">
                    Checklist Administratif
                </div>

                <div class="mb-4 text-sm text-slate-500">
                    Centang sesuai hasil verifikasi administratif untuk kesiapan pendaftaran lelang.
                    Checklist dapat diubah ulang apabila terdapat perubahan dokumen/data.
                </div>

                <div class="space-y-3">

                    @forelse($checklist as $i => $item)
                        <div class="rounded-xl border p-4">

                            <div class="flex items-start gap-3">

                                {{-- hidden id --}}
                                <input type="hidden"
                                       name="items[{{ $i }}][id]"
                                       value="{{ $item->id }}">

                                {{-- hidden unchecked --}}
                                <input type="hidden"
                                       name="items[{{ $i }}][is_checked]"
                                       value="0">

                                {{-- checkbox --}}
                                <input type="checkbox"
                                       name="items[{{ $i }}][is_checked]"
                                       value="1"
                                       {{ $item->is_checked ? 'checked' : '' }}
                                       class="mt-1 h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">

                                <div class="flex-1">

                                    <div class="font-semibold">
                                        {{ $item->check_label }}
                                        @if($item->is_required)
                                            <span class="text-rose-600">*</span>
                                        @endif
                                    </div>

                                    <div class="mt-1 text-xs text-slate-500">
                                        @if($item->is_checked)
                                            Dicentang oleh
                                            <b>{{ $item->checker?->name ?? 'Petugas' }}</b>
                                            pada
                                            {{ optional($item->checked_at)->format('d-m-Y H:i') }}
                                        @else
                                            Belum dicentang
                                        @endif
                                    </div>

                                    <textarea name="items[{{ $i }}][notes]"
                                              rows="2"
                                              class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500"
                                              placeholder="Catatan (opsional)">{{ old("items.$i.notes", $item->notes) }}</textarea>
                                </div>

                            </div>
                        </div>
                    @empty
                        <div class="rounded-lg bg-slate-50 p-4 text-sm text-slate-600">
                            Checklist administratif belum tersedia.
                        </div>
                    @endforelse

                </div>

                <div class="mt-5 flex justify-end">
                    <button type="submit"
                            class="inline-flex items-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        Simpan Checklist
                    </button>
                </div>

            </div>
        </form>

    @else

        {{-- ============================= --}}
        {{-- Read-only view (Non TL/KASI)  --}}
        {{-- ============================= --}}
        <div class="rounded-2xl border bg-slate-50 p-5">

            <div class="mb-1 text-lg font-bold">
                Checklist Administratif
            </div>

            <div class="mb-4 text-sm text-slate-500">
                Checklist ini hanya dapat dicentang oleh TL atau Kasi.
            </div>

            <div class="space-y-3">
                @forelse($checklist as $item)
                    <div class="rounded-xl border bg-white p-4">
                        <div class="flex items-start gap-3">
                            <input type="checkbox"
                                   disabled
                                   {{ $item->is_checked ? 'checked' : '' }}
                                   class="mt-1 h-4 w-4 rounded border-slate-300">

                            <div>
                                <div class="font-semibold">
                                    {{ $item->check_label }}
                                    @if($item->is_required)
                                        <span class="text-rose-600">*</span>
                                    @endif
                                </div>

                                <div class="mt-1 text-xs text-slate-500">
                                    @if($item->is_checked)
                                        Dicentang oleh
                                        <b>{{ $item->checker?->name ?? 'Petugas' }}</b>
                                        pada
                                        {{ optional($item->checked_at)->format('d-m-Y H:i') }}
                                    @else
                                        Belum dicentang
                                    @endif
                                </div>

                                @if($item->notes)
                                    <div class="mt-2 text-sm text-slate-700">
                                        <b>Catatan:</b><br>
                                        {{ $item->notes }}
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg bg-white p-4 text-sm text-slate-600">
                        Checklist administratif belum tersedia.
                    </div>
                @endforelse
            </div>
        </div>

    @endif

</div>
