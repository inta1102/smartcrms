<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LegacySyncRun extends Model
{
    use HasFactory;

    protected $table = 'legacy_sync_runs';

    protected $fillable = [
        'posisi_date',
        'total',
        'processed',
        'failed',
        'status',
        'started_at',
        'finished_at',
        'created_by',
    ];

    protected $casts = [
        'posisi_date' => 'immutable_date:Y-m-d',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
        'total'       => 'integer',
        'processed'   => 'integer',
        'failed'      => 'integer',
    ];


    // =========================
    // STATUS CONSTANTS
    // =========================
    public const STATUS_RUNNING   = 'running';
    public const STATUS_DONE      = 'done';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    // =========================
    // HELPER METHODS
    // =========================

    public function markRunning(): void
    {
        $this->update([
            'status'     => self::STATUS_RUNNING,
            'started_at'=> now(),
        ]);
    }

    public function markDone(): void
    {
        $this->update([
            'status'      => self::STATUS_DONE,
            'finished_at'=> now(),
        ]);
    }

    public function markFailed(): void
    {
        $this->update([
            'status'      => self::STATUS_FAILED,
            'finished_at'=> now(),
        ]);
    }

    public function incrementProcessed(int $by = 1): void
    {
        $this->increment('processed', $by);
    }

    public function incrementFailed(int $by = 1): void
    {
        $this->increment('failed', $by);
    }

    public function progressPercent(): float
    {
        if ($this->total <= 0) {
            return 0;
        }

        return round(($this->processed / $this->total) * 100, 2);
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        // biar konsisten ISO tapi tanpa timezone drama
        return $date->format('Y-m-d H:i:s');
    }

}
