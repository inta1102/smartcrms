<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class LoanAccountClosure extends Model
{
    protected $table = 'loan_account_closures';

    protected $fillable = [
        'account_no',
        'cif',
        'ao_code',
        'closed_date',
        'closed_month',
        'close_type',
        'source_status_raw',
        'os_at_prev_snapshot',
        'os_closed',
        'source_file',
        'import_batch_id',
        'imported_at',
        'note',
    ];

    protected $casts = [
        'closed_date'          => 'date:Y-m-d',
        'imported_at'          => 'datetime:Y-m-d H:i:s',
        'os_at_prev_snapshot'  => 'decimal:2',
        'os_closed'            => 'decimal:2',
    ];

    /**
     * Normalisasi field penting.
     */
    protected static function booted(): void
    {
        static::saving(function (self $m) {
            // account_no
            $m->account_no = trim((string) $m->account_no);

            // cif
            $m->cif = $m->cif !== null ? trim((string) $m->cif) : null;

            // ao_code fix 6 digit (nullable allowed)
            $ao = trim((string) ($m->ao_code ?? ''));
            $m->ao_code = $ao === '' ? null : str_pad($ao, 6, '0', STR_PAD_LEFT);

            // close_type uppercase
            $m->close_type = strtoupper(trim((string) $m->close_type));

            // raw status uppercase
            $m->source_status_raw = $m->source_status_raw !== null
                ? strtoupper(trim((string) $m->source_status_raw))
                : null;

            // closed_month derived from closed_date kalau kosong / invalid format
            if (!empty($m->closed_date)) {
                $month = Carbon::parse($m->closed_date)->format('Y-m');
                if (empty($m->closed_month) || !preg_match('/^\d{4}\-\d{2}$/', (string) $m->closed_month)) {
                    $m->closed_month = $month;
                }
            }

            // closed_month trim
            $m->closed_month = trim((string) $m->closed_month);
        });
    }

    // =========================
    // Scopes (biar query KPI enak)
    // =========================
    public function scopeMonth(Builder $q, string $ym): Builder
    {
        return $q->where('closed_month', $ym);
    }

    public function scopeType(Builder $q, string $type): Builder
    {
        return $q->where('close_type', strtoupper($type));
    }

    public function scopeAo(Builder $q, string $aoCode): Builder
    {
        $aoCode = str_pad(trim($aoCode), 6, '0', STR_PAD_LEFT);
        return $q->where('ao_code', $aoCode);
    }
}