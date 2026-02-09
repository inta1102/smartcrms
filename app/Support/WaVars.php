<?php

namespace App\Support;

class WaVars
{
    /**
     * Universal 5 vars mapping untuk template WA "ticket_notify_any".
     */
    public static function any5(
        string $title,
        string $context,
        string $action,
        string $extra,
        string $cta
    ): array {
        return [
            'var1' => $title,
            'var2' => $context,
            'var3' => $action,
            'var4' => $extra,
            'var5' => $cta,
        ];
    }
}
