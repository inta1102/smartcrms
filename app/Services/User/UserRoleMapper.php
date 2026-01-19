<?php

namespace App\Services\User;

use App\Enums\UserRole;

class UserRoleMapper
{
    public static function fromLegacy(string $value): UserRole
    {
        return UserRole::tryFrom(strtoupper(trim($value))) 
            ?? UserRole::STAFF;
    }
}
