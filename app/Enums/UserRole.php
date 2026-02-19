<?php

namespace App\Enums;

enum UserRole: string
{
    // Pengurus
    case DIREKSI = 'DIREKSI';
    case KOM = 'KOM';
    case DIR = 'DIR';

    // Kabag + Pejabat eksekutif
    case KABAG   = 'KABAG';
    case KBL     = 'KBL';
    case KBO     = 'KBO';
    case KTI     = 'KTI';
    case KBF     = 'KBF';
    case PE      = 'PE';

    // Kasi
    case KSLR     = 'KSLR';
    case KSO     = 'KSO';
    case KSA     = 'KSA';
    case KSF     = 'KSF';
    case KSD     = 'KSD';
    case KSLU     = 'KSLU';
    case KSFE     = 'KSFE';
    case KSBE     = 'KSBE';
    case KSR     = 'KSR';

    // Team Leader
    case TL      = 'TL';     // TL general
    case TLL     = 'TLL';    // TL Lending
    case TLF     = 'TLF';    // TL Funding
    case TLR     = 'TLR';    // TL Remedial

    // ✅ TL by division
    case TLRO    = 'TLRO';
    case TLFE    = 'TLFE';
    case TLBE    = 'TLBE';
    case TLSO    = 'TLSO';
    case TLUM    = 'TLUM';

    // Staff
    case AO      = 'AO';
    case CS      = 'CS';
    case TEL     = 'TEL';
    case BO      = 'BO';
    case ACC     = 'ACC';
    case BE      = 'BE';
    case SO      = 'SO';
    case TI      = 'TI';
    case SAD     = 'SAD';
    case SPE     = 'SPE';
    case SSD     = 'SSD';
    case RO      = 'RO';
    case SA      = 'SA';
    case FE      = 'FE';
    case FO      = 'FO';
    case STAFF   = 'STAFF';

    // =========================
    // ✅ GROUP HELPERS
    // =========================

    /** TL family (yang kamu minta) */
    public static function tlFamily(): array
    {
        return [self::TLRO, self::TLFE, self::TLBE, self::TLSO, self::TLUM];
    }

    /** Semua TL (general + legacy + family) */
    public static function tlAll(): array
    {
        return array_merge([self::TL, self::TLL, self::TLF, self::TLR], self::tlFamily());
    }

    /** cek string role/level termasuk TL* */
    public static function isTlValue(string|null $v): bool
    {
        $v = strtoupper(trim((string)$v));
        if ($v === '') return false;

        return in_array($v, array_map(fn($e) => $e->value, self::tlAll()), true);
    }

    public function rank(): int
    {
        return match ($this) {
            self::DIREKSI, self::KOM, self::DIR => 100,

            self::KABAG, self::KBL, self::KBO, self::KTI, self::KBF, self::PE => 80,

            self::KSFE, self::KSBE, self::KSLR, self::KSLU, self::KSO, self::KSA, self::KSF, self::KSD, self::KSR => 60,

            // ✅ TL group (pakai helper)
            self::tlAll() => 40,

            default => 20,
        };
    }

    public static function supervisors(): array
    {
        return [
            self::DIREKSI, self::KOM, self::DIR,
            self::KABAG, self::KBL, self::KBO, self::KTI, self::KBF, self::PE,
            self::KSFE, self::KSBE, self::KSLR, self::KSLU, self::KSO, self::KSA, self::KSF, self::KSD, self::KSR,
            ...self::tlAll(),
        ];
    }

    public static function staff(): array
    {
        return [
            self::AO, self::CS, self::TEL, self::BO, self::ACC,
            self::BE, self::SO, self::TI,
            self::SAD, self::SSD, self::SPE,
            self::FE, self::FO, self::RO, self::SA,
            self::STAFF,
        ];
    }

    public function isSupervisor(): bool
    {
        return in_array($this, self::supervisors(), true);
    }

    public function isManagement(): bool
    {
        return $this->rank() >= 60; // KASI ke atas
    }

    public function isTop(): bool
    {
        return $this->rank() >= 80; // Kabag/PE ke atas
    }
}
