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

        // KSA/KBO (dan legacy SAD) boleh lihat semua
        if (in_array($rv, ['KSA','KBO','SAD'], true)) {
            return true;
        }

        // Staff lapangan hanya boleh lihat milik sendiri
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
        return $req->requested_by === $user->id && in_array($req->status, [
            ShmCheckRequest::STATUS_SUBMITTED,
        ], true);
    }

    public function sadAction(User $user): bool
    {
        $rv = strtoupper(trim($user->roleValue() ?? ''));
        return in_array($rv, ['KSA','KBO','SAD'], true);
    }


    public function aoSignedUpload(User $user, ShmCheckRequest $req): bool
    {
        $rv = strtoupper(trim($user->roleValue() ?? ''));

        // hanya pemohon (atau AO yang login) dan status tertentu bisa upload signed
        if (!in_array($rv, ['AO','RO','SO','BE','FE'], true)) return false;

        return (int)$req->requested_by === (int)$user->id;
    }

}
