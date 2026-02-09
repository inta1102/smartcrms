<?php

namespace App\Policies;

use App\Models\ShmCheckRequest;
use App\Models\User;

class ShmCheckRequestPolicy
{
    public function viewAny(User $user): bool
    {
        $rv = strtoupper(trim($user->roleValue() ?? ''));

        return in_array($rv, [
            'AO','RO','SO','BE','FE',
            'KSA','KBO',
            'SAD',
        ], true);
    }

    public function view(User $user, ShmCheckRequest $req): bool
    {
        $rv = strtoupper(trim($user->roleValue() ?? ''));

        if (in_array($rv, ['KSA','KBO','SAD'], true)) {
            return true;
        }

        if (in_array($rv, ['AO','RO','SO','BE','FE'], true)) {
            return (int)$req->requested_by === (int)$user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['AO','SO','RO','BE','FE','SA']);
    }

    public function update(User $user, ShmCheckRequest $req): bool
    {
        // update data pengajuan hanya oleh pembuat selama belum diproses SAD
        if ($user->hasRole('SAD')) return true;

        return $req->requested_by === $user->id
            && in_array($req->status, [ShmCheckRequest::STATUS_SUBMITTED], true);
    }

    public function sadAction(User $user): bool
    {
        $rv = strtoupper(trim($user->roleValue() ?? ''));
        return in_array($rv, ['KSA','KBO','SAD'], true);
    }

    public function aoSignedUpload(User $user, ShmCheckRequest $req): bool
    {
        $rv = strtoupper(trim($user->roleValue() ?? ''));

        if (!in_array($rv, ['AO','RO','SO','BE','FE'], true)) return false;

        return (int)$req->requested_by === (int)$user->id;
    }

    /**
     * ✅ MODE CEPAT (sebelum lock):
     * SUBMITTED + pemohon => boleh ganti KTP/SHM langsung
     */
    public function replaceInitialFiles(User $user, ShmCheckRequest $req): bool
    {
        $rv = strtoupper(trim($user->roleValue() ?? ''));

        if (!in_array($rv, ['AO','RO','SO','BE','FE'], true)) return false;
        if ((int)$req->requested_by !== (int)$user->id) return false;

        // hanya ketika masih submitted
        if ($req->status !== ShmCheckRequest::STATUS_SUBMITTED) return false;

        // ✅ hanya jika belum dikunci (belum ada download/lock oleh SAD/KSA)
        return empty($req->initial_files_locked_at);
    }

    /**
     * ✅ OPSI A (sesudah lock):
     * SUBMITTED + sudah lock => pemohon boleh ajukan revisi
     */
    public function aoRevisionRequest(User $user, ShmCheckRequest $req): bool
    {
        $rv = strtoupper(trim($user->roleValue() ?? ''));

        if (!in_array($rv, ['AO','RO','SO','BE','FE'], true)) return false;
        if ((int)$req->requested_by !== (int)$user->id) return false;

        if ($req->status !== ShmCheckRequest::STATUS_SUBMITTED) return false;

        // ✅ hanya jika sudah dikunci
        return !empty($req->initial_files_locked_at);
    }

    /**
     * ✅ SAD/KSA/KBO approve request revisi
     */
    public function approveRevisionInitialDocs(User $user, ShmCheckRequest $req): bool
    {
        $rv = strtoupper(trim($user->roleValue() ?? ''));
        return in_array($rv, ['KSA','KBO','SAD'], true);
    }

    /**
     * ✅ Pemohon upload file revisi setelah di-approve
     */
    public function aoRevisionUpload(User $user, ShmCheckRequest $req): bool
    {
        $rv = strtoupper(trim($user->roleValue() ?? ''));

        if (!in_array($rv, ['AO','RO','SO','BE','FE'], true)) return false;
        if ((int)$req->requested_by !== (int)$user->id) return false;

        return $req->status === ShmCheckRequest::STATUS_REVISION_APPROVED;
    }
}
