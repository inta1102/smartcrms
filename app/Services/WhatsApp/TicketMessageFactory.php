<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * TicketMessageFactory
 *
 * Tugas:
 * - membentuk variabel template WA (universal 5 vars: {{1}}..{{5}})
 * - menyediakan helper URL/button path (REL) & full URL (ABS) konsisten
 *
 * Catatan:
 * - WA template universal 5 vars dipakai via WhatsAppNotifier::sendTemplate()
 * - Untuk button URL Qontak: biasanya butuh RELATIVE path tanpa leading slash (contoh: "tickets/0012")
 */
class TicketMessageFactory
{
    // =========================
    // Time helpers
    // =========================

    public static function formatWIB($ts): string
    {
        return Carbon::parse($ts)->timezone('Asia/Jakarta')->format('d M Y H:i') . ' WIB';
    }

    // =========================
    // Labels
    // =========================

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'open'        => 'Open',
            'in_progress' => 'In progress',
            'assigned'    => 'Assigned',
            'done'        => 'Done',
            'closed'      => 'Closed',
            default       => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    public static function priorityLabel(?string $priority): string
    {
        $p = strtolower(trim((string) $priority));
        return match ($p) {
            'low'    => 'Low',
            'medium' => 'Medium',
            'high'   => 'High',
            'urgent' => 'Urgent',
            ''       => '',
            default  => ucfirst($p),
        };
    }

    // =========================
    // Code helpers (FIXED)
    // =========================

    /**
     * Kode tiket dari id numerik (aman untuk builder by id).
     * Format: TKT-YYYY-000123
     */
    public static function codeFromId(int $ticketId): string
    {
        $num = str_pad((string) $ticketId, 6, '0', STR_PAD_LEFT);
        return 'TKT-' . now()->format('Y') . '-' . $num;
    }

    /**
     * Kode task dari id numerik.
     * Format: TSK-000123
     */
    public static function taskCodeFromId(int $taskId): string
    {
        return 'TSK-' . str_pad((string) $taskId, 6, '0', STR_PAD_LEFT);
    }

    // =========================
    // URL helpers (REL/ABS)
    // =========================

    /**
     * RELATIVE path untuk button (tanpa leading slash).
     * contoh: "tickets/12" atau "tasks/43"
     */
    protected static function rel(string $path): string
    {
        return ltrim($path, '/');
    }

    /**
     * Absolute URL: base(app.url) + "/" + rel(path)
     */
    protected static function abs(string $path): string
    {
        $base = rtrim(config('app.url', url('/')), '/');
        return $base . '/' . self::rel($path);
    }

    /**
     * Tickets: REL path untuk button.
     * Kamu bisa ganti pola kalau ticket show pakai code, bukan id.
     */
    public static function ticketButtonPath(int $ticketId): string
    {
        // contoh minimal: "tickets/123"
        return self::rel("tickets/{$ticketId}");
    }

    /**
     * Tickets: full URL untuk teks di WA
     */
    public static function ticketFullUrl(int $ticketId): string
    {
        // kalau route tickets.show ada, ini paling akurat:
        try {
            return route('tickets.show', ['ticket' => $ticketId], true);
        } catch (\Throwable $e) {
            // fallback kalau route tidak ada
            return self::abs("tickets/{$ticketId}");
        }
    }

    /**
     * Tasks: REL path untuk button.
     */
    public static function taskButtonPath(int $taskId): string
    {
        return self::rel("tasks/{$taskId}");
    }

    /**
     * Tasks: full URL untuk teks.
     */
    public static function taskFullUrl(int $taskId): string
    {
        // jika ada route tasks.show, silakan ganti ke route(); default abs()
        return self::abs("tasks/{$taskId}");
    }

    // =========================
    // Builders: UNIVERSAL 5 VARS
    // =========================

    /**
     * Menghasilkan array 5 variabel untuk template `ticket_notify_any`
     *
     * @param int   $ticketId
     * @param array $opt [
     *   audience => 'KTI'|'KABAG_TI'|'TI'|'PIC'|'USER'|'PEMOHON',
     *   subject, priority, requester_name, pic_name, status, created_at, note, overdue_days
     * ]
     *
     * @return array [var1,var2,var3,var4,var5]
     */
    public static function buildTicketNotifyAny(int $ticketId, array $opt): array
    {
        $status = self::statusLabel((string)($opt['status'] ?? 'open'));
        $code   = self::codeFromId($ticketId);

        $subject  = trim((string)($opt['subject'] ?? ''));
        $priority = self::priorityLabel($opt['priority'] ?? '');
        $subPri   = trim($subject . ($priority !== '' ? ' — ' . $priority : ''));

        $reqName  = (string)($opt['requester_name'] ?? 'Pemohon');
        $created  = $opt['created_at'] ?? now();
        $pemTgl   = trim($reqName . ' — ' . self::formatWIB($created));

        // detail: PIC + catatan + overdue + link
        $parts = [];
        if (!empty($opt['pic_name']))     $parts[] = 'PIC: ' . $opt['pic_name'];
        if (!empty($opt['note']))         $parts[] = 'Catatan: ' . Str::limit((string)$opt['note'], 160);
        if (!empty($opt['overdue_days'])) $parts[] = 'Overdue: ' . $opt['overdue_days'] . ' hari';
        $parts[] = 'Lihat: ' . self::ticketFullUrl($ticketId);
        $detail = implode(' ; ', $parts);

        // var1: salam dinamis
        $aud      = strtoupper((string)($opt['audience'] ?? 'TI'));
        $picName  = (string)($opt['pic_name'] ?? 'PIC');
        $ktiLabel = (string) config('whatsapp.greetings.kti', 'Pak Kabag TI');

        $var1 = match ($aud) {
            'KTI', 'KABAG_TI'     => "Halo {$ktiLabel}",
            'PEMOHON', 'USER'     => "Halo {$reqName} (pemohon)",
            'PIC'                 => "Halo {$picName}",
            'TI'                  => 'Halo tim TI',
            default               => 'Halo',
        };

        return [
            $var1,                 // {{1}}
            "{$status} · {$code}", // {{2}}
            $subPri,               // {{3}}
            $pemTgl,               // {{4}}
            $detail,               // {{5}}
        ];
    }

    /**
     * Task: universal 5 vars untuk template `ticket_notify_any`
     *
     * @param int   $taskId
     * @param array $opt [
     *   audience => 'PIC'|'USER'|'KTI'|'TI',
     *   subject, priority, requester_name, pic_name, status, created_at, note
     * ]
     */
    public static function buildTaskNotifyAny(int $taskId, array $opt): array
    {
        // salam fleksibel
        $aud  = strtoupper((string)($opt['audience'] ?? 'KTI'));
        $var1 = match ($aud) {
            'PIC'  => 'Halo ' . ((string)($opt['pic_name'] ?? 'PIC')),
            'USER', 'PEMOHON' => 'Halo ' . ((string)($opt['requester_name'] ?? 'Pemohon')) . ' (Pemohon)',
            'TI'   => 'Halo tim TI',
            default=> 'Halo ' . (string) config('whatsapp.greetings.kti', 'Pak Kabag TI'),
        };

        $status = self::statusLabel((string)($opt['status'] ?? 'open'));
        $code   = self::taskCodeFromId($taskId);

        $subject  = trim((string)($opt['subject'] ?? ''));
        $priority = self::priorityLabel($opt['priority'] ?? '');
        $subPri   = trim($subject . ($priority !== '' ? ' — ' . $priority : ''));

        $reqName  = (string)($opt['requester_name'] ?? 'Pemohon');
        $created  = $opt['created_at'] ?? now();
        $pemTgl   = trim($reqName . ' — ' . self::formatWIB($created));

        $viewUrl = self::taskFullUrl($taskId);

        $parts = [];
        if (!empty($opt['pic_name'])) $parts[] = 'PIC: ' . $opt['pic_name'];
        if (!empty($opt['note']))     $parts[] = 'Catatan: ' . Str::limit((string)$opt['note'], 160);
        $parts[] = 'Lihat: ' . $viewUrl;
        $detail = implode(' ; ', $parts);

        return [
            $var1,               // {{1}}
            "{$status} · {$code}", // {{2}}
            $subPri,             // {{3}}
            $pemTgl,             // {{4}}
            $detail,             // {{5}}
        ];
    }

    /**
     * Wrapper: Task assigned ke PIC
     */
    public static function buildTaskAssignToPICVars(int $taskId, array $opt): array
    {
        return self::buildTaskNotifyAny($taskId, [
            'audience'       => $opt['audience']       ?? 'PIC',
            'status'         => 'assigned',
            'subject'        => $opt['subject']        ?? '',
            'priority'       => $opt['priority']       ?? '',
            'requester_name' => $opt['requester_name'] ?? '',
            'pic_name'       => $opt['pic_name']       ?? '',
            'created_at'     => $opt['created_at']     ?? now(),
            'note'           => $opt['note']           ?? null,
        ]);
    }

    /**
     * Wrapper: Task progress update
     */
    public static function buildTaskProgressVars(int $taskId, array $opt): array
    {
        return self::buildTaskNotifyAny($taskId, [
            'audience'       => $opt['audience']       ?? 'PIC',
            'status'         => $opt['status']         ?? 'in_progress',
            'subject'        => $opt['subject']        ?? '',
            'priority'       => $opt['priority']       ?? '',
            'requester_name' => $opt['requester_name'] ?? '',
            'pic_name'       => $opt['pic_name']       ?? '',
            'created_at'     => $opt['created_at']     ?? now(),
            'note'           => $opt['note']           ?? null,
        ]);
    }

    // =========================
    // Legacy: payload pattern (template + vars + buttons)
    // =========================

    /**
     * Kalau kamu mau style "payload" (template + vars + buttons) seperti di kode lama kamu.
     *
     * @param string $templateName contoh: config('whatsapp.qontak.templates.ticket_notify_any')
     * @param array  $vars         5 vars
     * @param array  $buttons      contoh: [['type'=>'URL','index'=>'0','value'=>'tickets/123']]
     */
    public static function asPayload(string $templateName, array $vars, array $buttons = []): array
    {
        $payload = [
            'template' => $templateName,
            'vars'     => array_values($vars),
        ];

        if (!empty($buttons)) {
            $payload['buttons'] = $buttons;
        }

        return $payload;
    }

    /**
     * Shortcut: payload ticket notify any + 1 button (REL path)
     */
    public static function ticketNotifyAnyPayload(int $ticketId, array $opt): array
    {
        $tpl  = (string) config('whatsapp.qontak.templates.ticket_notify_any', '');
        $vars = self::buildTicketNotifyAny($ticketId, $opt);

        return self::asPayload($tpl, $vars, [
            [
                'type'  => 'URL',
                'index' => '0',
                'value' => self::ticketButtonPath($ticketId),
            ],
        ]);
    }

    /**
     * Shortcut: payload task notify any + 1 button (REL path)
     */
    public static function taskNotifyAnyPayload(int $taskId, array $opt): array
    {
        $tpl  = (string) config('whatsapp.qontak.templates.ticket_notify_any', '');
        $vars = self::buildTaskNotifyAny($taskId, $opt);

        return self::asPayload($tpl, $vars, [
            [
                'type'  => 'URL',
                'index' => '0',
                'value' => self::taskButtonPath($taskId),
            ],
        ]);
    }
}
