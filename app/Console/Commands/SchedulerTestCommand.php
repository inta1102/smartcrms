<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\NplCase;
use App\Models\ActionSchedule;
use Carbon\Carbon;

class SchedulerTestCommand extends Command
{
    protected $signature = 'scheduler:test {aoCode}';
    protected $description = 'Generate dummy schedules for testing Agenda AO UI';

    public function handle()
    {
        $aoCode = $this->argument('aoCode');

        $this->info("Membuat jadwal dummy untuk AO: {$aoCode}");

        // Ambil semua kasus milik AO ini
        $cases = NplCase::whereHas('loanAccount', function ($q) use ($aoCode) {
            $q->where('ao_code', $aoCode);
        })->get();

        if ($cases->isEmpty()) {
            $this->warn("Tidak ada kasus untuk AO {$aoCode}");
            return;
        }

        foreach ($cases as $case) {
            $this->info("→ Case ID {$case->id} ({$case->loanAccount->customer_name})");

            // Hapus schedule pending lama yang menempel ke NplCase ini (polymorphic)
            ActionSchedule::where('npl_case_id', $case->id)
                ->where('schedulable_type', \App\Models\NplCase::class)
                ->where('schedulable_id', $case->id)
                ->where('status', 'pending')
                ->delete();

            // Helper untuk bikin schedule (biar tidak repetitif)
            $make = function (string $type, string $title, string $notes, $when) use ($case) {
                $case->schedules()->create([
                    'npl_case_id'     => $case->id,
                    'type'            => $type,
                    'title'           => $title,
                    'notes'           => $notes,
                    'scheduled_at'    => $when,
                    'status'          => 'pending',
                    'created_by'      => null, // command, tidak ada auth user
                ]);
            };

            // Jadwal Overdue (kemarin)
            $make('follow_up', 'Follow-up tertunda', 'Jadwal dummy - overdue', now()->subDay());

            // Jadwal Today
            $make('follow_up', 'Follow-up hari ini', 'Jadwal dummy - today', now());

            // Jadwal future 1
            $make('follow_up', 'Follow-up besok', 'Jadwal dummy - +1 day', now()->addDay());

            // Jadwal future 3 days
            $make('follow_up', 'Follow-up +3 hari', 'Jadwal dummy - +3 days', now()->addDays(3));

            // Jadwal future 7 days
            $make('visit', 'Kunjungan Lapangan', 'Jadwal dummy - visit +7 days', now()->addDays(7));
        }

        $this->info("Berhasil generate jadwal dummy untuk AO {$aoCode}!");
    }
}
