<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiBeMonthly extends Model
{
    protected $table = 'kpi_be_monthlies';

    protected $fillable = [
        'period',
        'be_user_id',

        'actual_os_selesai',
        'actual_noa_selesai',
        'actual_bunga_masuk',
        'actual_denda_masuk',

        'score_os',
        'score_noa',
        'score_bunga',
        'score_denda',

        'pi_os',
        'pi_noa',
        'pi_bunga',
        'pi_denda',
        'total_pi',

        'os_npl_prev',
        'os_npl_now',
        'net_npl_drop',

        'status',
        'submitted_at',
        'submitted_by',
        'approved_at',
        'approved_by',
        'approval_note',
    ];

    protected $casts = [
        'period' => 'date',

        'actual_os_selesai' => 'decimal:2',
        'actual_bunga_masuk' => 'decimal:2',
        'actual_denda_masuk' => 'decimal:2',

        'pi_os' => 'decimal:2',
        'pi_noa' => 'decimal:2',
        'pi_bunga' => 'decimal:2',
        'pi_denda' => 'decimal:2',
        'total_pi' => 'decimal:2',

        'os_npl_prev' => 'decimal:2',
        'os_npl_now' => 'decimal:2',
        'net_npl_drop' => 'decimal:2',

        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'be_user_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
