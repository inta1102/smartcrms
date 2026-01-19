<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\NplCase;
use App\Services\CaseActionLegacySpSyncService;
use Illuminate\Support\Str;

class SyncLegacySp extends Command
{
    /**
     * options:
     * --case=1 atau --case=1,2,3  : hanya case tertentu
     * --dry-run                  : hanya tampilkan target, tidak eksekusi sync
     * --limit=200                : batas jumlah case diproses
     * --only-open=1              : hanya open cases
     * --force=1                  : paksa sync walau last_legacy_sync_at masih baru
     * --minutes=1440             : sync ulang jika last_legacy_sync_at lebih lama dari X menit
     */
    protected $signature = 'crms:sync-legacy-sp
                            {--case= : Hanya proses case_id tertentu (mis: 12 atau 12,15,20)}
                            {--dry-run : Hanya tampilkan target, tidak eksekusi}
                            {--limit=200 : Batas jumlah case diproses}
                            {--only-open=1 : 1=only open, 0=all}
                            {--force=0 : 1=paksa sync}
                            {--minutes=1440 : batas menit agar dianggap perlu resync}';

    protected $description = 'CRMS: Batch sync histori SP dari legacy ke case_actions untuk NPL cases.';

    public function handle(CaseActionLegacySpSyncService $sync): int
    {
        $limit    = max(1, (int) $this->option('limit'));
        $onlyOpen = ((int) $this->option('only-open')) === 1;
        $force    = ((int) $this->option('force')) === 1;
        $minutes  = max(0, (int) $this->option('minutes'));
        $dryRun   = (bool) $this->option('dry-run');

        $caseOpt = trim((string) $this->option('case'));

        // parse "1,2,3"
        $caseIds = [];
        if ($caseOpt !== '') {
            $caseIds = collect(explode(',', $caseOpt))
                ->map(fn ($v) => (int) trim($v))
                ->filter(fn ($v) => $v > 0)
                ->values()
                ->all();
        }

        $this->line(str_repeat('=', 55));
        $this->info('CRMS Sync Legacy SP');
        $this->line(str_repeat('-', 55));
        $this->line('Dry run     : ' . ($dryRun ? 'YA' : 'TIDAK'));
        $this->line('Case id(s)  : ' . (!empty($caseIds) ? implode(',', $caseIds) : '-'));
        $this->line("Limit       : {$limit}");
        $this->line('Only open   : ' . ($onlyOpen ? '1' : '0'));
        $this->line('Force       : ' . ($force ? '1' : '0'));
        $this->line("Minutes     : {$minutes}");

        $q = NplCase::query()
            ->with('loanAccount')
            ->when($onlyOpen, fn ($qq) => $qq->whereNull('closed_at'));

        // ✅ filter case_id bila diset
        if (!empty($caseIds)) {
            $q->whereIn('id', $caseIds);
        }

        // ✅ filter by last_legacy_sync_at bila tidak force
        if (!$force) {
            $q->where(function ($w) use ($minutes) {
                $w->whereNull('last_legacy_sync_at');
                if ($minutes > 0) {
                    $w->orWhere('last_legacy_sync_at', '<', now()->subMinutes($minutes));
                }
            });
        }

        // prioritaskan yang belum pernah sync
        $q->orderByRaw('CASE WHEN last_legacy_sync_at IS NULL THEN 0 ELSE 1 END ASC')
          ->orderBy('last_legacy_sync_at', 'asc')
          ->orderBy('id', 'asc')
          ->limit($limit);

        $cases = $q->get();

        $this->line('Found       : ' . $cases->count() . ' case(s)');
        $this->line(str_repeat('=', 55));

        if ($cases->isEmpty()) {
            $this->info('Tidak ada case yang perlu sync.');
            return self::SUCCESS;
        }

        // ✅ DRY RUN: tampilkan target saja
        if ($dryRun) {
            foreach ($cases as $case) {
                $rekening = (string) ($case->loanAccount?->account_no ?? $case->loanAccount?->no_rekening ?? '-');
                $this->line("DRY case_id={$case->id} rekening={$rekening} last_sync=" . ($case->last_legacy_sync_at ?: '-'));
            }
            $this->info('DRY RUN selesai (tidak ada perubahan data).');
            return self::SUCCESS;
        }

        $ok = 0;
        $fail = 0;

        foreach ($cases as $case) {
            try {
                $res = $sync->syncForCase($case);

                $isOk = (bool)($res['ok'] ?? false);
                $rekening = (string)($res['no_rekening'] ?? $case->loanAccount?->account_no ?? $case->loanAccount?->no_rekening ?? '-');
                $found = (int)($res['found'] ?? 0);
                $affected = (int)($res['inserted_or_updated'] ?? 0);
                $skipped = (int)($res['skipped'] ?? 0);

                if (!$isOk) {
                    $fail++;
                    $this->warn("FAIL case_id={$case->id} rekening={$rekening} message=" . ($res['message'] ?? 'unknown'));
                    continue;
                }

                $ok++;
                $this->line("OK  case_id={$case->id} rekening={$rekening} found={$found} affected={$affected} skipped={$skipped}");

            } catch (\Throwable $e) {
                $fail++;
                $this->warn("EXC case_id={$case->id}: " . $e->getMessage());
            }
        }

        $this->info("DONE. ok={$ok}, fail={$fail}");
        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
}
