<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Ao\AoAgendaProgressService;

class MarkAoAgendasOverdue extends Command
{
    protected $signature = 'ao-agendas:mark-overdue';
    protected $description = 'Mark AO agendas overdue if due_at has passed';

    public function handle(AoAgendaProgressService $svc): int
    {
        $count = $svc->markOverdue();
        $this->info("Updated {$count} agendas to overdue.");
        return Command::SUCCESS;
    }
}
