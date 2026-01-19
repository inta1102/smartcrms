<?php

namespace App\Policies;

use App\Models\User;
use App\Models\OrgAssignment;
use App\Enums\UserRole;
use App\Policies\Concerns\AuthorizesByRole;

class OrgAssignmentPolicy
{
    use AuthorizesByRole;

    public function viewAny(User $user): bool
    {
        return $this->allowRoles($user, [...$this->rolesKasi(), ...$this->rolesKabagPe()]);
    }

    public function create(User $user): bool
    {
        return $this->allowRoles($user, [...$this->rolesKasi(), ...$this->rolesKabagPe()]);
    }

    public function update(User $user, OrgAssignment $assignment): bool
    {
        return $this->allowRoles($user, [...$this->rolesKasi(), ...$this->rolesKabagPe()]);
    }

    public function end(User $user, OrgAssignment $assignment): bool
    {
        // aksi "Akhiri"
        return $this->allowRoles($user, [...$this->rolesKasi(), ...$this->rolesKabagPe()]);
    }
}
