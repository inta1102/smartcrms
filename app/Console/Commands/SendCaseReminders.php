<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ActionSchedule;
use App\Services\WhatsApp\QontakClient;
use Carbon\Carbon;

class SendCaseReminders extends Command
{
    protected $signature = 'cases:send-reminders';
    protected $description = 'Kirim WA reminder ke AO untuk jadwal tindakan kredit bermasalah';

    public function handle(QontakClient $wa): int
    {
        $now = Carbon::now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd   = $now->copy()->endOfDay();

        // Ambil schedule hari ini & overdue yang belum di-notify (atau terakhir notify > 1 hari)
        $schedules = ActionSchedule::pending()
            ->whereBetween('scheduled_at', [$todayStart->subDays(1), $todayEnd]) // termasuk yang lewat 1 hari
            ->with(['nplCase.loanAccount'])
            ->get();

        if ($schedules->isEmpty()) {
            $this->info('Tidak ada jadwal yang perlu di-remind.');
            return self::SUCCESS;
        }

        // Di sini idealnya kita grup per AO, tapi versi simple: kirim per schedule
        foreach ($schedules as $sch) {
            $case = $sch->nplCase;
            $loan = $case->loanAccount;

            // TODO: ambil nomor WA AO dari tabel AO / users (sementara dummy)
            $aoPhone = $loan->ao_phone ?? null;
            if (! $aoPhone) {
                continue;
            }

            // Contoh payload singkat
            $vars = [
                'nama_ao'      => $loan->ao_name,
                'nama_debitur' => $loan->customer_name,
                'account_no'   => $loan->account_no,
                'jenis'        => $sch->title ?? ucfirst($sch->type),
                'tanggal'      => $sch->scheduled_at->format('d-m-Y'),
            ];

            // Sesuaikan dg template Qontak yg sudah kamu punya
            $templateId = config('app.wa_template_reminder_case');

            $wa->sendDirect($aoPhone, $templateId, $vars);

            $sch->last_notified_at = $now;
            $sch->save();
        }

        $this->info('Reminder WA telah dikirim.');
        return self::SUCCESS;
    }
}
