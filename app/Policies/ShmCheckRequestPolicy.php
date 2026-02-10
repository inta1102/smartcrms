<?php

namespace App\Policies;

use App\Models\ShmCheckRequest;
use App\Models\User;

class ShmCheckRequestPolicy
{
    /**
     * Normalisasi roleValue supaya aman dari karakter aneh (contoh: "SO |", "KSA -", dll).
     */
    private function rv(User $user): string
    {
        $raw = strtoupper((string) ($user->roleValue() ?? ''));
        // ambil huruf A-Z saja -> "SO |" jadi "SO"
        return preg_replace('/[^A-Z]/', '', $raw) ?: '';
    }

    public function viewAny(User $user): bool
    {
        $rv = $this->rv($user);

        return in_array($rv, [
            'AO','RO','SO','BE','FE',
            'KSA','KBO','SAD',
        ], true);
    }

    public function view(User $user, ShmCheckRequest $req): bool
    {
        $rv = $this->rv($user);

        // admin scope
        if (in_array($rv, ['KSA','KBO','SAD'], true)) return true;

        // pemohon hanya miliknya sendiri
        if (in_array($rv, ['AO','RO','SO','BE','FE'], true)) {
            return (int) $req->requested_by === (int) $user->id;
        }

        return false;
    }

    public function create(User $user): bool
    {
        $rv = $this->rv($user);
        return in_array($rv, ['AO','SO','RO','BE','FE','SA'], true);
    }

    public function update(User $user, ShmCheckRequest $req): bool
    {
        $rv = $this->rv($user);

        // SAD boleh update (kalau kamu memang butuh)
        if ($rv === 'SAD') return true;

        // selain itu hanya pemohon & hanya saat SUBMITTED
        return (int) $req->requested_by === (int) $user->id
            && $req->status === ShmCheckRequest::STATUS_SUBMITTED;
    }

    public function sadAction(User $user): bool
    {
        $rv = $this->rv($user);
        return in_array($rv, ['KSA','KBO','SAD'], true);
    }

    public function aoSignedUpload(User $user, ShmCheckRequest $req): bool
    {
        $rv = $this->rv($user);

        if (!in_array($rv, ['AO','RO','SO','BE','FE'], true)) return false;
        return (int) $req->requested_by === (int) $user->id;
    }

    /**
     * MODE CEPAT (sebelum lock):
     * SUBMITTED + pemohon => boleh ganti KTP/SHM langsung
     */
    public function replaceInitialFiles(User $user, ShmCheckRequest $req): bool
    {
        $rv = $this->rv($user);

        if (!in_array($rv, ['AO','RO','SO','BE','FE'], true)) return false;
        if ((int) $req->requested_by !== (int) $user->id) return false;

        if ($req->status !== ShmCheckRequest::STATUS_SUBMITTED) return false;

        // hanya jika belum dikunci
        return empty($req->initial_files_locked_at);
    }

    /**
     * OPSI A (sesudah lock):
     * SUBMITTED + sudah lock => pemohon boleh ajukan revisi
     */
    public function aoRevisionRequest(User $user, ShmCheckRequest $req): bool
    {
        $rv = $this->rv($user);

        if (!in_array($rv, ['AO','RO','SO','BE','FE'], true)) return false;
        if ((int) $req->requested_by !== (int) $user->id) return false;

        if ($req->status !== ShmCheckRequest::STATUS_SUBMITTED) return false;

        // hanya jika sudah dikunci
        return !empty($req->initial_files_locked_at);
    }

    /**
     * SAD/KSA/KBO approve request revisi
     * hanya saat status REVISION_REQUESTED
     */
    public function approveRevisionInitialDocs(User $user, ShmCheckRequest $req): bool
    {
        $rv = $this->rv($user);

        if (!in_array($rv, ['KSA','KBO','SAD'], true)) return false;

        return $req->status === ShmCheckRequest::STATUS_REVISION_REQUESTED;
    }

    /**
     * Pemohon upload file revisi setelah di-approve
     */
    public function aoRevisionUpload(User $user, ShmCheckRequest $req): bool
    {
        $rv = $this->rv($user);

        if (!in_array($rv, ['AO','RO','SO','BE','FE'], true)) return false;
        if ((int) $req->requested_by !== (int) $user->id) return false;

        return $req->status === ShmCheckRequest::STATUS_REVISION_APPROVED;
    }
}
