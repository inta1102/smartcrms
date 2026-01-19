<?php

namespace App\Policies;

use App\Models\LegalActionProposal;
use App\Models\User;

class LegalActionProposalPolicy
{
    public const STATUS_SUBMITTED       = 'submitted';
    public const STATUS_APPROVED_TL     = 'approved_tl';
    public const STATUS_APPROVED_KASI   = 'approved_kasi';
    public const STATUS_REJECTED        = 'rejected';
    public const STATUS_EXECUTED        = 'executed';

    public function create(User $user): bool
    {
        return method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['AO','BE','FE','SO','RO','SA']);
    }

    /**
     * Index global (daftar proposal) -> biasanya untuk BE & approver.
     * AO tidak perlu akses global; AO cukup "My Proposals" (lihat catatan bawah).
     */
    public function viewAny(User $user): bool
    {
        return method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['BE','TLL','TLR','KSR','KSL','KBL','KTI','DIR','DIREKSI','KOM']);
    }

    public function view(User $user, LegalActionProposal $p): bool
    {
        if ($this->viewAny($user)) return true;

        // proposer boleh lihat usulannya sendiri
        return (int) $p->proposed_by === (int) $user->id;
    }

    public function submit(User $user, LegalActionProposal $p): bool
    {
        return (int) $p->proposed_by === (int) $user->id
            && ($p->status === LegalActionProposal::STATUS_DRAFT);
    }

    public function approveTl(User $user, LegalActionProposal $p): bool
    {
        return $user->hasAnyRole(['TL','TLL','TLR'])
            && (int)($p->needs_tl_approval ?? 1) === 1
            && $p->status === LegalActionProposal::STATUS_PENDING_TL;
    }

    public function approveKasi(User $user, LegalActionProposal $p): bool
    {
        return $user->hasAnyRole(['KSR','KSL'])
            && $p->status === LegalActionProposal::STATUS_PENDING_KASI;
    }

    public function execute(User $user, LegalActionProposal $p): bool
    {
        return $user->hasAnyRole(['BE'])
            && $p->status === LegalActionProposal::STATUS_APPROVED_KASI
            && empty($p->legal_action_id);
    }


    /**
     * Optional: siapa yang boleh reject di masing-masing step.
     * (kalau controller kamu punya rejectTl/rejectKasi)
     */
    public function rejectTl(User $user, LegalActionProposal $p): bool
    {
        return method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['TLL','TLR'])
            && ($p->status === LegalActionProposal::STATUS_SUBMITTED);
    }

    public function rejectKasi(User $user, LegalActionProposal $p): bool
    {
        return method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['KSR','KSL'])
            && ($p->status === LegalActionProposal::STATUS_APPROVED_TL);
    }
}
