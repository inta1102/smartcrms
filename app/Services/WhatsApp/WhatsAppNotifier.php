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

        // Untuk endpoint direct:
        $channelIntegrationId = (string) config('whatsapp.qontak.channel_id');

        if ($base === '' || $path === '' || $token === '' || $channelIntegrationId === '') {
            Log::error('Qontak WA config missing', compact('base', 'path', 'channelIntegrationId'));
            throw new \RuntimeException('Qontak WA config missing (base_url/endpoint/api_token/channel_id).');
        }

        $toNumber = $this->normalizeRecipient($to);

        // Direct endpoint hanya terima nomor, bukan UUID/group
        if (preg_match('/[a-zA-Z]/', $toNumber) || str_contains($toNumber, '-')) {
            throw new \RuntimeException("Qontak direct requires phone number, got: {$toNumber}");
        }

        $toName = (string)($meta['to_name'] ?? 'Recipient');

        // parameters.body: value = param name (var1..varN), value_text = real text
        $bodyParams = [];
        $i = 1;
        foreach (array_values($vars) as $val) {
            $bodyParams[] = [
                'key'        => (string) $i,
                'value'      => 'var' . $i,      // 2-16 char, lowercase/number/_
                'value_text' => (string) $val,   // actual message text
            ];
            $i++;
        }

        $parameters = [
            'body' => $bodyParams,
        ];

        // parameters.buttons: value = param name, value_text = path
        if (!empty($meta['buttons']) && is_array($meta['buttons'])) {
            $parameters['buttons'] = array_map(function ($b) {
                $val = ltrim((string)($b['value'] ?? ''), '/');
                $idx = (string)($b['index'] ?? '0');

                return [
                    'index'      => $idx,
                    'type'       => strtolower((string)($b['type'] ?? 'url')),
                    'value'      => 'btn' . $idx, // btn0, btn1 ...
                    'value_text' => $val,         // relative path
                ];
            }, $meta['buttons']);
        }

        $payload = [
            'to_name'                => $toName,
            'to_number'              => $toNumber,
            'message_template_id'    => $template,
            'channel_integration_id' => $channelIntegrationId,
            'language'               => ['code' => config('whatsapp.defaults.language', 'id')],
            'parameters'             => $parameters,
        ];

        if (!empty($meta['extra']) && is_array($meta['extra'])) {
            $payload = array_replace_recursive($payload, $meta['extra']);
        }

        $url = "{$base}/{$path}";

        $resp = Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->post($url, $payload);

        if (!$resp->successful()) {
            Log::error('Qontak WA send failed', [
                'url'     => $url,
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
