<?php

namespace App\Enums;

enum UserRole: string
{
    // Pengurus
    case DIREKSI = 'DIREKSI';
    case KOM = 'KOM';
    case DIR = 'DIR';

    //Kabag + Pejabat eksekutif
    case KABAG   = 'KABAG';
    case KBL     = 'KBL';   // Kabag Lending
    case KBO     = 'KBO';   // Kabag Operasional
    case KTI     = 'KTI';   // Kabag TI
    case KBF     = 'KBF';   // Kabag Funding (kalau ada)
    
    case PE      = 'PE';    // Pejabat Eksekutif

    //kasi
    case KSL     = 'KSL';   // Kasi Lending
    case KSO     = 'KSO';   // Kasi Operasional
    case KSA     = 'KSA';   // Kasi Administrasi
    case KSF     = 'KSF';   // Kasi Funding
    case KSD     = 'KSD';   // Kasi SDM
    case KSR     = 'KSR';   // Kasi Remedial (kalau ada)

    //Team Leader
    case TL      = 'TL';    // TL general
    case TLL     = 'TLL';   // TL Lending (kalau dipisah)
    case TLF     = 'TLF';   // TL Funding (kalau dipisah)
    case TLR     = 'TLR';   // TL Funding (kalau dipisah)

    // Staff
    case AO      = 'AO';
    case CS      = 'CS';
    case TEL     = 'TEL';
    case BO      = 'BO';
    case ACC     = 'ACC';
    case BE     = 'BE';
    case SO     = 'SO';
    case TI      = 'TI';
    case SAD      = 'SAD';
    case SPE      = 'SPE';
    case SSD      = 'SSD';
    case RO      = 'RO';
    case SA      = 'SA';
    case FE      = 'FE';
    case FO      = 'FO';
    case STAFF   = 'STAFF';

    public function rank(): int
    {
        return match ($this) {
            self::DIREKSI, self::KOM, self::DIR => 100,

            self::KABAG, self::KBL, self::KBO, self::KTI, self::KBF, self::PE => 80,

            self::KSL, self::KSO, self::KSA, self::KSF, self::KSD, self::KSR => 60,

            self::TL, self::TLL, self::TLF, self::TLR => 40,

            default => 20,
        };
    }

        public static function supervisors(): array
    {
        return [
            self::DIREKSI, self::KOM, self::DIR,
            self::KABAG, self::KBL, self::KBO, self::KTI, self::KBF, self::PE,
            self::KSL, self::KSO, self::KSA, self::KSF, self::KSD, self::KSR,
            self::TL, self::TLL, self::TLF, self::TLR,
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
