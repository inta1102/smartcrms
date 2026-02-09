<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class WhatsAppNotifier
{
    public function enabled(): bool
    {
        return (bool) config('whatsapp.enabled');
    }

    /**
     * @param string $to      Nomor HP (08xx/62xx/+62xx) atau Group/Contact ID (UUID) bila vendor support
     * @param string $template Nama/ID template di Qontak (hasil mapping config('whatsapp.qontak.templates.*'))
     * @param array  $vars    Array variables untuk template, urut {{1}}..{{n}}
     * @param array  $meta    Optional: ['buttons' => [...], 'extra' => [...]]
     */
    public function sendTemplate(string $to, string $template, array $vars, array $meta = []): void
    {
        if (!$this->enabled()) {
            Log::info('[WA][DISABLED]', compact('to','template','vars','meta'));
            return;
        }

        $driver = config('whatsapp.driver', 'log');

        match ($driver) {
            'qontak' => $this->sendViaQontak($to, $template, $vars, $meta),
            default  => $this->sendViaLog($to, $template, $vars, $meta),
        };
    }

    protected function sendViaLog(string $to, string $template, array $vars, array $meta = []): void
    {
        Log::info('[WA][LOG]', compact('to','template','vars','meta'));
    }

    protected function sendViaQontak(string $to, string $template, array $vars, array $meta = []): void
    {
        $base  = rtrim((string) config('whatsapp.qontak.base_url'), '/');
        $path  = ltrim((string) config('whatsapp.qontak.endpoint_send_template', ''), '/');
        $token = (string) config('whatsapp.qontak.api_token');
        $chan  = config('whatsapp.qontak.channel_id'); // opsional

        if ($base === '' || $path === '' || $token === '') {
            Log::error('Qontak WA config missing', compact('base','path'));
            throw new \RuntimeException('Qontak WA config missing (base_url/endpoint/api_token).');
        }

        $payload = [
            'to'       => $this->normalizeRecipient($to),
            'template' => [
                'name'     => $template,
                'language' => config('whatsapp.defaults.language', 'id'),
                'params'   => array_values($vars), // {{1}}..{{n}}
            ],
        ];

        if (!empty($chan)) $payload['channel_id'] = $chan;

        // === tombol URL (opsional) ===
        if (!empty($meta['buttons']) && is_array($meta['buttons'])) {
            $buttons = array_map(function ($b) {
                $val = $b['value'] ?? '';
                $val = ltrim((string) $val, '/'); // WAJIB: "tickets/0012" (relative)

                return [
                    'type'  => strtoupper($b['type'] ?? 'URL'),
                    'index' => (string) ($b['index'] ?? '0'),
                    'value' => $val,
                ];
            }, $meta['buttons']);

            $payload['buttons'] = $buttons;
        }

        // meta tambahan vendor
        if (!empty($meta['extra']) && is_array($meta['extra'])) {
            $payload = array_merge($payload, $meta['extra']);
        }

        $resp = Http::withToken($token)
            ->acceptJson()
            ->post("{$base}/{$path}", $payload);

        if (!$resp->successful()) {
            Log::error('Qontak WA send failed', [
                'status'  => $resp->status(),
                'body'    => $resp->body(),
                'payload' => $payload,
            ]);
            throw new \RuntimeException('Qontak WA send failed: '.$resp->status().' '.$resp->body());
        }
    }

    /**
     * Kalau "to" adalah nomor hp -> normalisasi ke 62xxxxxxxx
     * Kalau "to" adalah UUID / group id -> biarkan apa adanya.
     */
    protected function normalizeRecipient(string $raw): string
    {
        $raw = trim($raw);

        // kalau berisi huruf atau dash (UUID), jangan diubah
        if (preg_match('/[a-zA-Z]/', $raw) || str_contains($raw, '-')) {
            return $raw;
        }

        // normalisasi nomor
        $s = preg_replace('/\D+/', '', $raw);
        if (str_starts_with($s, '0')) $s = '62' . substr($s, 1);
        if (str_starts_with($s, '620')) $s = '62' . substr($s, 3); // jaga-jaga input +620xxx
        return $s;
    }
}
