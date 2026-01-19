
<div class="rounded-2xl border border-slate-100 bg-white px-5 py-4 shadow-sm"> 
    <div class="mb-3">
        <h2 class="text-base font-bold text-slate-900">📚 Timeline Penanganan</h2>
        <p class="mt-0.5 text-sm text-slate-500">Riwayat tindakan yang pernah dicatat.</p>
    </div>

    @if ($case->actions->count() === 0)
        <p class="text-xs text-slate-500">
            Belum ada log progres. Silakan tambahkan tindakan pertama.
        </p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-xs">
                <thead class="border-b border-slate-100 bg-slate-50">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Waktu</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Jenis</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Deskripsi</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Hasil</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Next Action</th>
                        <th class="px-3 py-2 text-left font-semibold text-slate-600">Aksi</th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($case->actions as $action)
                        @php
                            // 1) Normalize meta (array|null)
                            $meta = $action->meta;
                            if (is_string($meta)) $meta = json_decode($meta, true);
                            if (!is_array($meta)) $meta = [];

                            // 2) Detect special sources
                            $sourceSystem = (string)($action->source_system ?? '');
                            $actionType   = strtoupper((string)($action->action_type ?? '-'));
                            $resultText   = (string)($action->result ?? '');

                            // Legacy proof
                            $isLegacy = ($sourceSystem === 'legacy_sp') && !empty($action->source_ref_id);
                            $hasProof = (bool)($meta['has_proof'] ?? false);
                            if (!$hasProof && $resultText === 'BUKTI:ADA') $hasProof = true;

                            // Non-Litigasi (submit/approve/reject)
                            $isNonLit = \Illuminate\Support\Str::startsWith($sourceSystem, 'non_litigation');
                            $nonLitId = $action->source_ref_id ?: ($meta['non_litigation_action_id'] ?? null);
                            $nonLitUrl = $nonLitId ? route('nonlit.show', $nonLitId) : null;

                            // Legal (create/status/etc)
                            $isLegal = \Illuminate\Support\Str::startsWith($sourceSystem, 'legal_')
                                || $actionType === 'LEGAL';

                            $legalId = $action->source_ref_id ?: ($meta['legal_action_id'] ?? null);

                            $legalUrl = null;
                            if ($legalId) {
                                $legal = \App\Models\LegalAction::select('id','action_type')->find($legalId);

                                if ($legal && ($legal->action_type ?? '') === \App\Models\LegalAction::TYPE_HT_EXECUTION) {
                                    $legalUrl = route('legal-actions.ht.show', [
                                        'action' => $legal->id,
                                        'tab' => 'summary',
                                    ]);
                                } else {
                                    $legalUrl = route('legal-actions.show', $legalId);
                                }
                            }

                            // 3) Badge styles per type
                            $badgeClass = match (true) {
                                $isLegal => 'bg-rose-50 text-rose-700 ring-rose-200',
                                $isNonLit => 'bg-indigo-50 text-indigo-700 ring-indigo-200',
                                $actionType === 'VISIT' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                in_array($actionType, ['SP1','SP2','SP3','SPT','SPJAD'], true) => 'bg-amber-50 text-amber-700 ring-amber-200',
                                default => 'bg-slate-100 text-slate-700 ring-slate-200',
                            };

                            // Result chip style
                            $resultUpper = strtoupper(trim($resultText));
                            $resultClass = match (true) {
                                str_contains($resultUpper, 'APPROV') => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                str_contains($resultUpper, 'REJECT') => 'bg-rose-50 text-rose-700 ring-rose-200',
                                str_contains($resultUpper, 'DRAFT')  => 'bg-slate-100 text-slate-700 ring-slate-200',
                                str_contains($resultUpper, 'SUBMIT') => 'bg-blue-50 text-blue-700 ring-blue-200',
                                str_contains($resultUpper, 'DONE')   => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                default => 'bg-slate-100 text-slate-700 ring-slate-200',
                            };

                            $timeText = $action->action_at ? $action->action_at->format('d-m-Y H:i') : '-';

                            $desc = (string)($action->description ?? '');
                            $descLines = preg_split("/\r\n|\n|\r/", $desc);
                        @endphp

                        <tr class="align-top border-b border-slate-100 last:border-b-0">
                            <td class="whitespace-nowrap px-3 py-2 text-slate-700">
                                {{ $timeText }}
                            </td>

                            <td class="px-3 py-2 text-slate-700">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $badgeClass }}">
                                    {{ $actionType }}
                                </span>

                                @if($isLegacy)
                                    <div class="mt-1 text-[10px] text-slate-400">Imported from Legacy SP</div>
                                @endif

                                @if($isLegal && !empty($meta['legal_type']))
                                    <div class="mt-1 text-[10px] text-slate-400">
                                        {{ strtoupper((string)$meta['legal_type']) }}
                                    </div>
                                @endif
                            </td>

                            <td class="px-3 py-2 text-slate-700">
                                @if (!empty($descLines))
                                    <div class="space-y-1">
                                        @foreach($descLines as $line)
                                            @if(trim($line) !== '')
                                                <div class="leading-relaxed">{{ $line }}</div>
                                            @endif
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-slate-400">-</span>
                                @endif
                            </td>

                            <td class="px-3 py-2 text-slate-700">
                                @if(trim($resultText) !== '')
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 {{ $resultClass }}">
                                        {{ $resultUpper }}
                                    </span>
                                @else
                                    <span class="text-[11px] text-slate-300">-</span>
                                @endif
                            </td>

                            <td class="px-3 py-2 text-slate-700">
                                @if ($action->next_action)
                                    <div class="leading-relaxed">{{ $action->next_action }}</div>
                                @else
                                    <div class="text-slate-300">-</div>
                                @endif

                                @if ($action->next_action_due)
                                    <div class="mt-1 text-[10px] text-slate-400">
                                        Target: {{ $action->next_action_due->format('d-m-Y') }}
                                    </div>
                                @endif
                            </td>

                            <td class="whitespace-nowrap px-3 py-2 text-slate-700">
                                <div class="flex items-center gap-2">

                                    @if($isLegal && $legalUrl)
                                        <a href="{{ $legalUrl }}"
                                            class="inline-flex items-center gap-1 rounded-lg border border-rose-200 px-2 py-1 text-rose-700 hover:bg-rose-50">
                                            <span>⚖️</span>
                                            <span class="text-[11px] font-semibold">Detail Legal</span>
                                        </a>

                                    @elseif($isNonLit && $nonLitUrl)
                                        <a href="{{ $nonLitUrl }}"
                                            class="inline-flex items-center gap-1 rounded-lg border border-indigo-200 px-2 py-1 text-indigo-700 hover:bg-indigo-50"
                                            title="Buka detail usulan Non-Litigasi">
                                            <span>📄</span>
                                            <span class="text-[11px] font-semibold">Detail Usulan</span>
                                        </a>

                                    @elseif($isLegacy && $hasProof)
                                        <a href="{{ route('cases.actions.legacy_proof', [$case->id, $action->id]) }}"
                                            target="_blank"
                                            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 px-2 py-1 text-slate-700 hover:bg-slate-50"
                                            title="Lihat bukti tanda terima (Legacy)">
                                            <span>👁</span>
                                            <span class="text-[11px] font-semibold">Bukti</span>
                                        </a>
                                    @elseif($isLegacy)
                                        <span class="inline-flex items-center rounded-lg border border-slate-100 bg-slate-50 px-2 py-1 text-slate-400"
                                                title="Belum ada bukti di Legacy">
                                            <span class="text-[11px] font-semibold">Bukti: Belum</span>
                                        </span>
                                    @else
                                        <span class="text-[11px] text-slate-300">-</span>
                                    @endif

                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
