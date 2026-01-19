<?php

namespace App\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalActionHtExecution extends Model
{
    protected $table = 'legal_action_ht_executions';

    public const METHOD_PARATE      = 'parate';
    public const METHOD_PN          = 'pn';
    public const METHOD_BAWAH_TANGAN = 'bawah_tangan';

    protected $fillable = [
        'legal_action_id',
        'method',
        'basis_default_at',
        'collateral_summary',
        'ht_deed_no',
        'ht_cert_no',
        'land_cert_type',
        'land_cert_no',
        'owner_name',
        'object_address',
        'appraisal_value',
        'outstanding_at_start',
        'notes',
        'locked_at',
        'lock_reason',
    ];

    protected $casts = [
        'basis_default_at' => 'date',
        'appraisal_value' => 'decimal:2',
        'outstanding_at_start' => 'decimal:2',
        'locked_at' => 'datetime',
    ];

    public function legalAction(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LegalAction::class, 'legal_action_id');
    }

    public function isLocked(): bool
    {
        return !is_null($this->locked_at);
    }
}
