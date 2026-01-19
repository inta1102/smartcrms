<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use App\Models\LegalEvent;
use App\Services\WhatsApp\QontakClient; // sesuaikan dengan service WA kamu
use App\Models\User;

class LegalSendReminders extends Command
{
    protected $signature = 'legal:send-reminders {--limit=50}';
    protected $description = 'Send reminders for legal events (WhatsApp)';

    public function handle(): int
    {
        $now   = now();
        $limit = (int) $this->option('limit');

        // Ambil event due yang belum pernah direminder
        $events = LegalEvent::query()
            ->where('status', 'scheduled')
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', $now)
            ->whereNull('reminded_at')
            ->where(function ($q) {
                // remind_channels json berisi ["whatsapp", ...]
                $q->whereJsonContains('remind_channels', 'whatsapp')
                  ->orWhereNull('remind_channels'); // optional: jika kamu default-kan WA tanpa set json
            })
            ->orderBy('remind_at')
            ->limit($limit)
            ->get();

        if ($events->isEmpty()) {
            $this->info('No due reminders.');
            return self::SUCCESS;
        }

        $client = app(QontakClient::class);

        $sent = 0;
        foreach ($events as $ev) {
            try {
                DB::transaction(function () use ($ev, $client, &$sent) {
                    // lock row biar tidak double jika cron overlap
                    $locked = LegalEvent::whereKey($ev->id)->lockForUpdate()->first();

                    if (!$locked || $locked->reminded_at !== null) {
                        return;
                    }

                    // Load relasi secukupnya (kalau ada relasi)
                    $locked->load(['legalCase', 'legalAction']);

                    // Tentukan penerima WA
                    // 1) Paling aman: config WA admin legal / kti
                    $to = config('legal.reminder_whatsapp_to'); // misal "62812xxxx"
                    $toName = config('legal.reminder_whatsapp_to_name', 'Tim Legal');

                    if (!$to) {
                        throw new \RuntimeException('Config legal.reminder_whatsapp_to belum diset.');
                    }

                    // Siapkan isi pesan (template vars)
                    $caseNo   = $locked->legalCase?->legal_case_no ?? '-';
                    $actionTy = $locked->legalAction?->action_type ?? '-';
                    $eventAt  = Carbon::parse($locked->event_at)->format('d M Y H:i');

                    $title = $locked->title ?? $locked->event_type;

                    // Kirim WA (pakai template Qontak kamu)
                    $templateId = config('legal.reminder_template_id'); // set di config
                    if (!$templateId) {
                        throw new \RuntimeException('Config legal.reminder_template_id belum diset.');
                    }

                    $vars = [
                        'title'      => $title,
                        'case_no'    => $caseNo,
                        'action'     => $actionTy,
                        'event_at'   => $eventAt,
                        'event_type' => $locked->event_type,
                    ];

                    // Tombol link ke halaman show action/case (optional)
                    $buttons = [];
                    if ($locked->legal_action_id) {
                        $buttons[] = [
                            'type' => 'url',
                            'text' => 'Buka Action',
                            'url'  => route('legal-actions.show', $locked->legal_action_id),
                        ];
                    }

                    $client->sendDirect($to, $templateId, $vars, $buttons, $toName);

                    // Mark reminded
                    $locked->reminded_at = now();
                    $locked->reminded_by = auth()->id() ?? null; // cron biasanya null
                    $locked->save();

                    $sent++;
                });
            } catch (\Throwable $e) {
                \Log::error('[LEGAL][REMINDER] gagal kirim', [
                    'event_id' => $ev->id,
                    'error'    => $e->getMessage(),
                ]);
                // lanjut event berikutnya, jangan stop satu batch
                continue;
            }
        }

        $this->info("Sent reminders: {$sent}");
        return self::SUCCESS;
    }
}
