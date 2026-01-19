<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Models\NplCase;
use App\Models\LegalCase;
use App\Models\LegalAction;
use App\Models\LegalActionProposal;
use App\Models\NonLitigationAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class NplLegalActionController extends Controller
{


    public function create(Request $request, NplCase $case)
    {
        $proposalId = (int) $request->query('proposal_id');

        $proposal = LegalActionProposal::query()
            ->whereKey($proposalId)
            ->where('npl_case_id', $case->id)
            ->firstOrFail();

        $this->authorize('execute', $proposal);

        // hanya boleh eksekusi yg approved kasi
        if ($proposal->status !== LegalActionProposal::STATUS_APPROVED_KASI) {
            abort(422, 'Proposal belum disetujui Kasi atau sudah dieksekusi.');
        }

        /**
         * ===============================
         * 1️⃣ Cari / Buat LEGAL CASE
         * ===============================
         */
        $existingLegalCase = LegalCase::query()
            ->where('npl_case_id', $case->id)
            ->first();

        if (!$existingLegalCase) {
            $existingLegalCase = LegalCase::create([
                'npl_case_id'   => $case->id,
                'legal_case_no' => $this->generateLegalCaseNo($case),
                'status'        => 'legal_init',
                'created_by'    => auth()->id(),
            ]);
        }

        /**
         * ===============================
         * 2️⃣ Daftar jenis tindakan
         * ===============================
         */
        $types = [
            LegalAction::TYPE_SOMASI          => 'Somasi',
            LegalAction::TYPE_HT_EXECUTION    => 'Eksekusi HT',
            LegalAction::TYPE_FIDUSIA_EXEC    => 'Eksekusi Fidusia',
            LegalAction::TYPE_CIVIL_LAWSUIT   => 'Gugatan Perdata',
            LegalAction::TYPE_PKPU_BANKRUPTCY => 'PKPU / Pailit',
            LegalAction::TYPE_CRIMINAL_REPORT => 'Laporan Pidana',
        ];

        return view('npl.legal_actions.create', [
            'case'              => $case,
            'legalCase'         => $existingLegalCase, // ⬅️ INI YANG DIPAKAI
            'proposal'          => $proposal,
            'types'             => $types,
        ]);
    }

    public function store(Request $request, NplCase $case)
    {
        $validated = $request->validate([
            'proposal_id'  => ['required', 'integer', 'exists:legal_action_proposals,id'],
            'notes'        => ['nullable', 'string', 'max:4000'],
            'ao_agenda_id' => ['nullable', 'integer', 'exists:ao_agendas,id'],
        ]);

        $proposal = LegalActionProposal::query()
            ->whereKey((int) $validated['proposal_id'])
            ->where('npl_case_id', $case->id)
            ->first();

        if (!$proposal) {
            return back()->withErrors(['legal' => 'Proposal tidak ditemukan untuk kasus ini.']);
        }

        $this->authorize('execute', $proposal);

        $userId = (int) auth()->id();
        $type   = strtolower((string) $proposal->action_type);

        // =====================================================
        // RULE BACKEND (minimal, tapi nyambung dg data real)
        // =====================================================
        $hasHandlingLog = \App\Models\CaseAction::query()
            ->where('npl_case_id', $case->id)
            ->where(function ($q) {
                $q->whereIn('action_type', [
                    // persuasif & collection
                    'persuasif', 'visit', 'call', 'negosiasi', 'penagihan',

                    // SP variasi
                    'sp', 'sp1', 'sp2', 'sp3', 'spak', 'spt', 'spjad',

                    // Non-lit variasi
                    'nonlit', 'non_litigasi', 'non_litigation',

                    // legal timeline (somasi selesai, dll)
                    'legal', 'LEGAL',
                ])
                ->orWhereIn('source_system', [
                    'legacy_sp',
                    'non_litigation',
                    'non_litigation_submit',
                    'legal_somasi',
                    'legal_action_create',
                ]);
            })
            ->exists();

        if (!$hasHandlingLog) {
            return back()->withErrors([
                'legal' => 'Belum bisa membuat Legal Action. Minimal harus ada log penanganan yang jelas (SP / Non-Litigasi / Visit/Call) di timeline.',
            ]);
        }

        // =====================================================
        // VALIDASI LITIGASI (SKIP HT eksekusi dulu)
        // =====================================================
        $litigationTypesExceptHt = [
            LegalAction::TYPE_FIDUSIA_EXEC,
            LegalAction::TYPE_CIVIL_LAWSUIT,
            LegalAction::TYPE_PKPU_BANKRUPTCY,
            LegalAction::TYPE_CRIMINAL_REPORT,
        ];

        if (in_array($type, array_map('strtolower', $litigationTypesExceptHt), true)) {

            $hasSp = \App\Models\CaseAction::query()
                ->where('npl_case_id', $case->id)
                ->where(function ($q) {
                    $q->whereIn('action_type', ['sp', 'spak', 'sp1', 'sp2', 'sp3', 'spt', 'spjad'])
                    ->orWhere('source_system', 'legacy_sp');
                })
                ->exists();

            $hasNonLitApproved = NonLitigationAction::query()
                ->where('npl_case_id', $case->id)
                ->where('status', NonLitigationAction::STATUS_APPROVED)
                ->exists();

            // fallback kalau memang ada kasus approval nonlit hanya tercatat di CaseAction
            if (!$hasNonLitApproved) {
                $hasNonLitApproved = \App\Models\CaseAction::query()
                    ->where('npl_case_id', $case->id)
                    ->where(function ($q) {
                        $q->where('action_type', 'non_litigasi')
                        ->orWhere('source_system', 'non_litigation');
                    })
                    ->where('description', 'like', '%APPROVED%')
                    ->exists();
            }

            if (!$hasSp || !$hasNonLitApproved) {
                return back()->withErrors([
                    'legal' => 'Belum memenuhi syarat litigasi. Pastikan sudah ada proses SP dan Non-Litigasi (APPROVED).',
                ]);
            }
        }

        // =========================
        // TRANSACTION (anti double execute)
        // =========================
        $action = DB::transaction(function () use ($case, $validated, $userId, $proposal) {

            $proposalLocked = LegalActionProposal::query()
                ->whereKey($proposal->id)
                ->lockForUpdate()
                ->firstOrFail();

            // ✅ status final sebelum execute: harus APPROVED oleh KASI
            if (($proposalLocked->status ?? '') !== LegalActionProposal::STATUS_APPROVED_KASI) {
                abort(422, 'Proposal belum final approved oleh Kasi.');
            }

            if (!empty($proposalLocked->legal_action_id)) {
                abort(422, 'Proposal sudah pernah dieksekusi.');
            }

            $legalCase = LegalCase::firstOrCreate(
                ['npl_case_id' => $case->id],
                ['legal_case_no' => 'LC-' . now()->format('YmdHis'), 'status' => 'legal_init']
            );

            $last = LegalAction::where('legal_case_id', $legalCase->id)
                ->orderByDesc('sequence_no')
                ->lockForUpdate()
                ->first();

            $nextSeq = (int) (($last->sequence_no ?? 0) + 1);

            $action = LegalAction::create([
                'legal_case_id' => $legalCase->id,
                'action_type'   => $proposalLocked->action_type, // sumber kebenaran = proposal
                'sequence_no'   => $nextSeq,
                'status'        => LegalAction::STATUS_DRAFT,
                'notes'         => $validated['notes'] ?? $proposalLocked->notes ?? null,
                'start_at'      => now(),
            ]);

            // HT seed checklist
            if (($action->action_type ?? '') === LegalAction::TYPE_HT_EXECUTION) {
                app(\App\Services\Legal\HtExecutionChecklistSeeder::class)->seed($action, $userId);
            }

            // timeline (idempotent)
            $exists = \App\Models\CaseAction::where('npl_case_id', $case->id)
                ->where('source_system', 'legal_action_create')
                ->where('source_ref_id', $action->id)
                ->exists();

            if (!$exists) {
                $typeUp = strtoupper((string) $action->action_type);

                \App\Models\CaseAction::create([
                    'npl_case_id'   => $case->id,
                    'user_id'       => $userId,
                    'source_system' => 'legal_action_create',
                    'source_ref_id' => $action->id,
                    'ao_agenda_id'  => $validated['ao_agenda_id'] ?? null,
                    'action_at'     => now(),
                    'action_type'   => 'legal',
                    'description'   => "LEGAL dieksekusi (DRAFT): {$typeUp}"
                        . (!empty($action->notes) ? "\nCatatan: " . trim((string) $action->notes) : ''),
                    'result'        => 'DRAFT',
                    'next_action'   => "Lengkapi data & submit {$typeUp}",
                    'meta'          => [
                        'legal_case_id'   => $legalCase->id,
                        'legal_action_id' => $action->id,
                        'legal_type'      => $action->action_type,
                        'sequence_no'     => $action->sequence_no,
                        'status'          => $action->status,
                        'proposal_id'     => $proposalLocked->id,
                    ],
                ]);
            }

            // mark executed
            $proposalLocked->status          = LegalActionProposal::STATUS_EXECUTED;
            $proposalLocked->executed_by     = $userId;
            $proposalLocked->executed_at     = now();
            $proposalLocked->legal_action_id = $action->id;
            $proposalLocked->save();

            // set case legal flag
            if ((int)($case->is_legal ?? 0) !== 1) {
                $case->is_legal = 1;
                $case->save();
            }

            return $action;
        });

        // ✅ Redirect sesuai tipe (HT -> route ht.show)
        $redirectTo = $this->resolveRedirectAfterCreate($action);

        return redirect($redirectTo)
            ->with('success', 'Legal Action berhasil dibuat dari proposal (DRAFT).');
    }



    protected function resolveRedirectAfterCreate(LegalAction $action): string
    {
        $type = strtolower((string) $action->action_type);

        if ($type === strtolower(LegalAction::TYPE_HT_EXECUTION)) {
            // route kamu: /{action}/ht
            return route('ht.show', $action);
        }

        // default
        return route('legal-actions.show', $action) . '?tab=overview';
    }

    protected function needsTlApprovalForUser(int $userId): bool
    {
        $oa = \App\Models\OrgAssignment::query()
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->first();

        if (!$oa) return true; // default aman: butuh TL

        return in_array(strtoupper((string)$oa->leader_role), ['TL', 'TLL', 'TLR'], true);
    }

}
