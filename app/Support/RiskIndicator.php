<?php

namespace App\Support;

class RiskIndicator
{
    public static function nplColor($pct)
    {
        if ($pct < 3) return 'green';
        if ($pct < 5) return 'yellow';
        return 'red';
    }
}