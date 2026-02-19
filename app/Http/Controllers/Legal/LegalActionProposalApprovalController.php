<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Models\LegalActionProposal;
use App\Models\OrgAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\LegalCase;
use App\Models\CaseAction;


class LegalActionProposalApprovalController extends Controller
{
    /**
     * TL approve proposal (pending_tl -> approved_tl)
     * - wajib catatan
     * - hanya jika proposal memang butuh TL approval
     */
    public function approveTl(Request $request, LegalActionProposal $proposal)
    {
        $user = auth()->user();
        abort_unless($user, 403);
        abort_unless($user->hasAnyRole(['TL','TLL','TLR','TLRO','TLSO','TLFE','TLBE','TLUM']), 403);

        $data = $request->validate([
            'approval_notes' => ['required', 'string', 'max:2000'],
        ]);

        if ((int)($proposal->needs_tl_approval ?? 1) !== 1) {
            return back()->with('status', 'Proposal ini tidak memerlukan approval TL (langsung ke Kasi).');
        }

        // visibility bawahan TL
        $today = now()->toDateString();
        $isSubordinate = OrgAssignment::query()
            ->where('leader_id', $user->id)
            ->where('user_id', $proposal->proposed_by)
            ->where('is_active', 1)
            ->whereDate('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            })
            ->exists();
        abort_unless($isSubordinate, 403);

        if ($proposal->status !== LegalActionProposal::STATUS_PENDING_TL) {
            return back()->with('status', 'Status proposal sudah berubah, tidak bisa di-approve TL.');
        }

        DB::transaction(function () use ($proposal, $user, $data) {
            $p = LegalActionProposal::query()
                ->whereKey($proposal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($p->status !== LegalActionProposal::STATUS_PENDING_TL) {
                abort(422, 'Status proposal sudah berubah.');
            }

            $p->status            = LegalActionProposal::STATUS_APPROVED_TL; // ✅ antrian kasi
            $p->approved_tl_by    = (int) $user->id;
            $p->approved_tl_at    = now();
            $p->approved_tl_notes = $data['approval_notes'];
            $p->save();
        });

        return back()->with('status', '✅ TL approve. Proposal masuk antrian Kasi.');
    }

    /**
     * Kasi approve proposal:
     * - jika needs_tl_approval = 1: hanya boleh dari approved_tl
     * - jika needs_tl_approval = 0: boleh langsung dari pending_kasi
     * - wajib catatan
     * - TIDAK membuat LegalAction (eksekusi tetap oleh BE)
     */
    public function approveKasi(Request $request, LegalActionProposal $proposal)
    {
        $user = auth()->user();
        abort_unless($user, 403);
        abort_unless($user->hasAnyRole(['KSLU','KSLR','KSFE','KSBE']), 403);

        $data = $request->validate([
            'approval_notes' => ['required', 'string', 'max:2000'],
        ]);

        // ✅ inbox Kasi hanya yang sudah approved TL
        if ($proposal->status !== LegalActionProposal::STATUS_APPROVED_TL) {
            return back()->with('status', 'Status proposal tidak valid untuk approval Kasi.');
        }

        abort_unless($this->isWithinKasiScope((int)$user->id, (int)$proposal->proposed_by), 403);

        DB::transaction(function () use ($proposal, $user, $data) {

            /** @var \App\Models\LegalActionProposal $p */
            $p = LegalActionProposal::query()
                ->whereKey($proposal->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($p->status !== LegalActionProposal::STATUS_APPROVED_TL) {
                abort(422, 'Status proposal sudah berubah.');
            }

            // =========================
            // 1) Update status proposal
            // =========================
            $p->status              = LegalActionProposal::STATUS_APPROVED_KASI;
            $p->approved_kasi_by    = (int) $user->id;
            $p->approved_kasi_at    = now();
            $p->approved_kasi_notes = $data['approval_notes'];
            $p->save();

            // =========================
            // 2) Flag legal case boleh
            // =========================
            $case = $p->nplCase;
            if ($case && (int)($case->is_legal ?? 0) !== 1) {
                $case->is_legal = 1;
                $case->save();
            }

            // =========================
            // 3) Ensure legal_case (opsional)
            // =========================
            $legalCase = null;

            if ($case) {
                $legalCase = LegalCase::query()
                    ->where('npl_case_id', $case->id)
                    ->lockForUpdate()
                    ->first();

                if (!$legalCase) {
                    $datePrefix = now()->format('Ymd');

                    $last = LegalCase::query()
                        ->where('legal_case_no', 'like', "LC-{$datePrefix}-%")
                        ->orderByDesc('id')
                        ->lockForUpdate()
                        ->first();

                    $nextSeq = 1;
                    if ($last && preg_match('/LC-\d{8}-(\d+)/', (string)$last->legal_case_no, $m)) {
                        $nextSeq = ((int) $m[1]) + 1;
                    }

                    $legalCaseNo = "LC-{$datePrefix}-" . str_pad((string) $nextSeq, 4, '0', STR_PAD_LEFT);

                    $legalCase = LegalCase::create([
                        'legal_case_no' => $legalCaseNo,
                        'npl_case_id'   => $case->id,
                        'status'        => 'open',
                        'created_by'    => (int) $user->id,
                    ]);
                }
            }

            // =========================
            // 4) ✅ TIMELINE LOG (CaseAction)
            //    supaya muncul di Timeline Penanganan
            // =========================
            if ($case) {
                // normalize meta agar konsisten dan kebaca di UI (meta['legal_type'])
                $meta = [
                    'proposal_id' => (int) $p->id,
                    'legal_type'  => (string) $p->action_type, // <-- UI kamu baca ini
                    'status'      => (string) $p->status,
                    'legal_case_id' => $legalCase?->id,
                ];

                // idempotent event key
                $sourceSystem = 'legal_proposal_approve_kasi';
                $sourceRefId  = (string) $p->id;

                \App\Models\CaseAction::query()->firstOrCreate(
                    [
                        'npl_case_id'   => (int) $case->id,
                        'source_system' => $sourceSystem,
                        'source_ref_id' => $sourceRefId,
                    ],
                    [
                        'action_type'  => 'LEGAL', // biar badge legal lebih tegas (optional, tapi enak)
                        'action_at'    => now(),
                        'result'       => 'APPROVED_KASI',
                        'description'  => "KASI approve usulan legal: " . strtoupper((string) $p->action_type)
                            . "\nCatatan: " . (string) $data['approval_notes'],
                        'next_action'  => (strtolower((string) $p->action_type) === 'plakat')
                            ? 'Lakukan pemasangan plakat sesuai rencana dan laporkan bukti pemasangan.'
                            : 'BE mengeksekusi tindakan legal sesuai usulan.',
                        'meta'         => json_encode($meta),
                    ]
                );
            }
        });

        return back()->with('success', '✅ Approved Kasi. Proposal siap dieksekusi BE.');
    }
    /**
     * Kasi scope:
     * - true jika proposer direct subordinate Kasi (leader_id = kasi)
     * - atau proposer subordinate TL yang leader_id = kasi (2-level)
     */
    protected function isWithinKasiScope(int $kasiId, int $proposedByUserId): bool
    {
        $today = now()->toDateString();

        // 1) direct subordinate
        $direct = OrgAssignment::query()
            ->where('leader_id', $kasiId)
            ->where('user_id', $proposedByUserId)
            ->where('is_active', 1)
            ->whereDate('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            })
            ->exists();

        if ($direct) return true;

        // 2) subordinate melalui TL: cari TL yang leader_id = kasi
        $tlIds = OrgAssignment::query()
            ->where('leader_id', $kasiId)
            ->where('is_active', 1)
            ->whereDate('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            })
            ->pluck('user_id')
            ->all();

        if (empty($tlIds)) return false;

        // lalu cek apakah proposer ada di bawah TL tersebut
        return OrgAssignment::query()
            ->whereIn('leader_id', $tlIds)
            ->where('user_id', $proposedByUserId)
            ->where('is_active', 1)
            ->whereDate('effective_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
            })
            ->exists();
    }

    protected function needsTlApprovalForUser(int $userId): bool
    {
        $oa = OrgAssignment::query()
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->first();

        if (!$oa) return true; // default aman: butuh TL

        return in_array(strtoupper((string)$oa->leader_role), ['TL', 'TLL', 'TLR','TLRO','TLSO','TLFE','TLBE','TLUM'], true);
    }
}
