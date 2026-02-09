<?php

namespace App\Services\WhatsApp;

use App\Models\ShmCheckRequest;
use Illuminate\Support\Carbon;

class ShmMessageFactory
{
    // =========================
    // Format helpers
    // =========================

    public static function formatWIB($ts): string
    {
        return Carbon::parse($ts)->timezone('Asia/Jakarta')->format('d M Y H:i') . ' WIB';
    }

    /**
     * Salam dinamis untuk template universal 5 vars.
     * audience:
     * - 'SAD' / 'KSA' -> sapaan petugas
     * - 'USER' / 'PEMOHON' -> sapaan pemohon
     */
    protected static function greeting(string $audience, ?string $requesterName = null): string
    {
        $aud = strtoupper(trim($audience));

        $ksaLabel = (string) config('whatsapp.greetings.ksa', 'Pak Kasi Administrasi');
        $sadLabel = (string) config('whatsapp.greetings.sad', 'Bagian SAD');

        return match ($aud) {
            'KSA' => "Halo {$ksaLabel}",
            'SAD' => "Halo {$sadLabel}",
            'PEMOHON', 'USER' => 'Halo ' . ($requesterName ?: 'Pemohon'),
            default => 'Halo',
        };
    }

    // =========================
    // URL helpers (mirip TicketMessageFactory)
    // =========================

    protected static function rel(string $path): string
    {
        return ltrim($path, '/'); // e.g. "shm/123"
    }

    protected static function abs(string $path): string
    {
        $base = rtrim(config('app.url', url('/')), '/');
        return $base . '/' . self::rel($path);
    }

    /**
     * Path untuk button (RELATIVE, tanpa slash depan)
     * Pastikan route show kamu adalah /shm/{req}
     * Kalau beda, tinggal ubah di sini.
     */
    public static function shmButtonPath(ShmCheckRequest $req): string
    {
        // contoh: "shm/12" (pakai id) atau "shm/SHM-202602-0001" tergantung route binding
        // karena di controller kamu route('shm.show', $req) pakai model binding.
        // paling aman: pakai id.
        return self::rel('shm/' . (string) $req->id);
    }

    /**
     * Full URL untuk teks (biar bisa di-klik dari WA)
     */
    public static function shmFullUrl(ShmCheckRequest $req): string
    {
        // Lebih aman pakai route absolute kalau route ada.
        // Tapi biar konsisten dengan TicketMessageFactory, kita bikin dari abs().
        return self::abs('shm/' . (string) $req->id);
    }

    // =========================
    // Builders (5 vars)
    // =========================

    /**
     * 1) Pemohon submit -> WA ke KSA/SAD (follow up)
     *
     * var1: salam
     * var2: status + request_no
     * var3: ringkas data utama
     * var4: pemohon + waktu submit
     * var5: detail + link
     */
    public static function buildSubmitToSadVars(ShmCheckRequest $req, array $opt = []): array
    {
        // audience bisa 'SAD' atau 'KSA' (kalau kamu blast ke group, bebas pilih)
        $aud = strtoupper($opt['audience'] ?? 'SAD');

        $reqName = $opt['requester_name']
            ?? ($req->requester?->name ?? 'Pemohon');

        $submittedAt = $req->submitted_at ?? $req->created_at ?? now();

        $var1 = self::greeting($aud, $reqName);

        // var2: status · kode
        $var2 = 'SUBMITTED' . ' · ' . ($req->request_no ?? ('SHM-' . str_pad((string)$req->id, 6, '0', STR_PAD_LEFT)));

        // var3: ringkasan inti (siapa & debitur)
        $debtor   = $req->debtor_name ?? '-';
        $certNo   = $req->certificate_no ?? '-';
        $branch   = $req->branch_code ?? '-';

        $var3 = trim("Debitur: {$debtor} — SHM: {$certNo}");

        // var4: pemohon + waktu
        $var4 = trim(($reqName ?: '-') . ' — ' . self::formatWIB($submittedAt));

        // var5: detail + link
        $parts = [];
        $parts[] = 'Cabang: ' . $branch;
        if (!empty($req->collateral_address)) $parts[] = 'Alamat agunan: ' . $req->collateral_address;
        if (!empty($req->notes)) $parts[] = 'Catatan: ' . str($req->notes)->limit(160);
        $parts[] = 'Lihat: ' . self::shmFullUrl($req);

        $var5 = implode(' ; ', $parts);

        return [$var1, $var2, $var3, $var4, $var5];
    }

    /**
     * 2) KSA/SAD upload SP & SK -> WA ke Pemohon (follow up tanda tangan + upload signed)
     *
     * var1: salam pemohon
     * var2: status · request_no
     * var3: instruksi singkat
     * var4: notaris + waktu upload
     * var5: detail + link
     */
    public static function buildSpSkUploadedToRequesterVars(ShmCheckRequest $req, array $opt = []): array
    {
        $reqName = $opt['requester_name']
            ?? ($req->requester?->name ?? 'Pemohon');

        $uploadedAt = $req->sp_sk_uploaded_at ?? now();

        $var1 = self::greeting('USER', $reqName);

        $var2 = 'SP/SK UPLOADED' . ' · ' . ($req->request_no ?? ('SHM-' . str_pad((string)$req->id, 6, '0', STR_PAD_LEFT)));

        $debtor = $req->debtor_name ?? '-';

        // var3: action
        $var3 = "SP & SK untuk debitur {$debtor} sudah tersedia. Silakan download, minta TTD debitur, lalu upload kembali (signed).";

        // var4: notaris + waktu
        $notary = $req->notary_name ?? '-';
        $var4 = "Notaris: {$notary} — " . self::formatWIB($uploadedAt);

        // var5: detail + link
        $parts = [];
        if (!empty($req->certificate_no)) $parts[] = 'No SHM: ' . $req->certificate_no;
        if (!empty($req->notes)) $parts[] = 'Catatan: ' . str($req->notes)->limit(160);
        $parts[] = 'Lihat: ' . self::shmFullUrl($req);

        $var5 = implode(' ; ', $parts);

        return [$var1, $var2, $var3, $var4, $var5];
    }

    // =========================
    // Optional: payload helper kalau kamu mau sekaligus meta buttons
    // =========================

    /**
     * Kalau kamu mau style seperti ticketNotify() yang return array:
     * ['template' => ..., 'vars' => [...], 'buttons' => [...]]
     */
    public static function asPayload(string $template, array $vars, ?ShmCheckRequest $reqForButton = null): array
    {
        $payload = [
            'template' => $template,
            'vars'     => array_values($vars),
        ];

        if ($reqForButton) {
            $payload['buttons'] = [
                [
                    'type'  => 'URL',
                    'index' => '0',
                    'value' => self::shmButtonPath($reqForButton), // REL path
                ],
            ];
        }

        return $payload;
    }

    public static function buildSignedUploadedToSadVars(\App\Models\ShmCheckRequest $req, array $opt = []): array
    {
        $audience = $opt['audience'] ?? 'SAD';
        $reqName  = $opt['requester_name'] ?? ($req->requester?->name ?? 'Pemohon');

        $title = "SIGNED UPLOADED · {$req->request_no}";
        $debtor = "Debitur: {$req->debtor_name}" . " — SHM: " . ($req->certificate_no ?: '-');
        $who = "{$reqName} — " . now()->translatedFormat('d M Y H:i') . " WIB";
        $branch = "Cabang: " . ($req->branch_code ?: '-') . " ; Lihat: " . url(self::shmButtonPath($req));

        return [
            "Halo Bagian {$audience}",
            $title,
            $debtor,
            $who,
            $branch,
        ];
    }

    public static function buildResultUploadedToRequesterVars(\App\Models\ShmCheckRequest $req, array $opt = []): array
    {
        $reqName = $opt['requester_name'] ?? ($req->requester?->name ?? 'Pemohon');

        $title = "HASIL UPLOADED · {$req->request_no}";
        $debtor = "Debitur: {$req->debtor_name}" . " — SHM: " . ($req->certificate_no ?: '-');
        $who = "SAD/KSA — " . now()->translatedFormat('d M Y H:i') . " WIB";
        $branch = "Cabang: " . ($req->branch_code ?: '-') . " ; Lihat: " . url(self::shmButtonPath($req));

        return [
            "Halo {$reqName}",
            $title,
            $debtor,
            $who,
            $branch,
        ];
    }

}
