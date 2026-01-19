<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\LoanAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SendRestrukturDueWhatsapp extends Command
{
    protected $signature = 'wa:restruktur-due-h5';
    protected $description = 'Kirim WA H-5 sebelum jatuh tempo bayar untuk kredit restruktur (dalam masa monitoring 3 bulan).';

    public function handle(): int
    {
        $targetDate = now()->addDays(5)->toDateString();

        // Sesuaikan: pakai field due yang sudah ada di loan_accounts
        $dueField = 'next_action_due'; // <-- GANTI jika due date kamu beda

        $rows = LoanAccount::query()
            ->where('is_restructured', true)
            ->whereNotNull('monitor_restruktur_until')
            ->whereDate('monitor_restruktur_until', '>=', now()->toDateString())
            ->whereDate($dueField, '=', $targetDate)
            ->get();

        foreach ($rows as $acc) {
            $key = "wa:restruktur:h5:loan:{$acc->id}:{$targetDate}";

            if (Cache::has($key)) continue;

            // anti-spam tambahan
            if ($acc->last_restruktur_wa_sent_at && $acc->last_restruktur_wa_sent_at->isToday()) {
                continue;
            }

            try {
                // Tentukan penerima:
                // - AO/PIC internal (paling aman)
                // contoh: $acc->aoUser->wa_number atau mapping ao_code->user
                // di sini aku contohkan pseudo:
                $to = $acc->ao_wa_number ?? null; // <-- sesuaikan sumbernya

                if (!$to) continue;

                $msg = "🔔 Reminder Kredit Restruktur (H-5)\n"
                     . "Debitur: {$acc->customer_name}\n"
                     . "Rek: {$acc->account_no}\n"
                     . "Jatuh tempo: {$targetDate}\n"
                     . "Monitoring s/d: ".$acc->monitor_restruktur_until?->format('Y-m-d')."\n"
                     . "Mohon dipantau & follow-up.";

                // Panggil service WA kamu (job queue lebih baik)
                // SendWhatsappJob::dispatch($to, $msg);
                app(\App\Services\WhatsappService::class)->send($to, $msg);

                $acc->forceFill(['last_restruktur_wa_sent_at' => now()])->save();

                Cache::put($key, 1, now()->addDays(2)); // kunci 2 hari biar aman
            } catch (\Throwable $e) {
                Log::warning("WA restruktur H-5 gagal (loan={$acc->id}): ".$e->getMessage());
            }
        }

        $this->info("Done. target={$targetDate}, rows=".$rows->count());
        return self::SUCCESS;
    }
}
