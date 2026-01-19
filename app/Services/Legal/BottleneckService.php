<?php

namespace App\Services\Legal;

use App\Models\LegalAction;
use Illuminate\Support\Carbon;

class BottleneckService
{
    public const NONE    = null;
    public const WARNING = 'warning'; // kuning
    public const DANGER  = 'danger';  // merah

    public static function detect(LegalAction $action, ?Carbon $now = null): ?string
    {
        $now = $now ?: now();

        $x = (int) config('legal.bottleneck.sent_not_received_days', 7);
        $y = (int) config('legal.bottleneck.received_no_response_days', 14);

        $status = strtolower((string) ($action->status ?? ''));

        // 1) SENT > X hari tapi belum RECEIVED
        if ($status === 'sent') {
            $sentAt = $action->sent_at ?: $action->created_at; // fallback kalau belum ada sent_at
            if ($sentAt && $sentAt->copy()->addDays($x)->lt($now)) {
                return self::WARNING;
            }
        }

        // 2) RECEIVED tapi no response > Y hari
        if ($status === 'received') {
            $receivedAt = $action->received_at;
            $responseAt = $action->response_at; // pastikan field ini ada / sesuaikan

            if ($receivedAt && empty($responseAt) && $receivedAt->copy()->addDays($y)->lt($now)) {
                return self::DANGER;
            }
        }

        return self::NONE;
    }

    public static function label(?string $level): ?string
    {
        return match ($level) {
            self::WARNING => 'SENT terlalu lama, belum RECEIVED',
            self::DANGER  => 'RECEIVED terlalu lama, belum ada respon',
            default       => null,
        };
    }
}
