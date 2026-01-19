<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;

class RoleResolver
{
    public static function resolve(User $user): UserRole
    {
        // 1) role utama dari kolom baru
        $raw = (string)($user->level_role ?? '');
        $raw = strtoupper(trim($raw));

        if ($raw !== '') {
            return self::safeEnum($raw);
        }

        // 2) fallback dari kolom legacy "level"
        $legacy = (string)($user->level ?? '');
        $legacy = strtoupper(trim($legacy));

        if ($legacy !== '') {
            // mapping legacy -> role enum
            $mapped = self::mapLegacyLevel($legacy);
            return self::safeEnum($mapped);
        }

        // 3) default aman
        return UserRole::STAFF;
    }

    private static function mapLegacyLevel(string $legacy): string
    {
        // Sesuaikan mapping kamu.
        // Contoh: kalau legacy "DIR" kamu pakai jadi "DIREKSI"
        return match ($legacy) {
            'DIR' => 'DIREKSI',
            default => $legacy,
        };
    }

    private static function safeEnum(string $value): UserRole
    {
        // Kalau value tidak dikenali, jangan bikin kacau: jatuh ke STAFF
        return UserRole::tryFrom($value) ?? UserRole::STAFF;
    }
}
