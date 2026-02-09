<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWaTemplateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max attempts (retry) kalau gagal (network/vendor error)
     */
    public int $tries = 5;

    /**
     * Timeout per job (detik)
     */
    public int $timeout = 25;

    /**
     * Backoff antar retry (detik)
     * Bisa array: [10, 30, 60, 120, 300]
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    /**
     * Kamu bisa override queue name saat dispatch via onQueue('wa')
     */
    public function __construct(
        public string $to,
        public string $template,
        public array $vars = [],
        public array $meta = []
    ) {
        // default queue WA (boleh diubah kalau kamu pakai queue lain)
        $this->onQueue('wa');
    }

    public function handle(WhatsAppNotifier $wa): void
    {
        // kalau WA global dimatikan, Notifier sudah handle juga,
        // tapi kita short-circuit untuk hemat queue.
        if (!(bool) config('whatsapp.enabled', true)) {
            Log::info('[WA][JOB][SKIP_DISABLED]', [
                'to' => $this->to,
                'template' => $this->template,
            ]);
            return;
        }

        // Vars harus berurutan {{1}}..{{n}}; pastikan indexed array
        $vars = array_values($this->vars ?? []);

        $wa->sendTemplate(
            $this->to,
            $this->template,
            $vars,
            is_array($this->meta) ? $this->meta : []
        );

        Log::info('[WA][JOB][SENT]', [
            'to' => $this->to,
            'template' => $this->template,
            'vars_count' => count($vars),
            'has_buttons' => !empty($this->meta['buttons'] ?? null),
        ]);
    }

    /**
     * Dipanggil saat job benar2 gagal setelah semua retry habis
     */
    public function failed(Throwable $e): void
    {
        Log::error('[WA][JOB][FAILED]', [
            'to' => $this->to,
            'template' => $this->template,
            'vars' => $this->vars,
            'meta' => $this->meta,
            'error' => $e->getMessage(),
        ]);
    }
}
