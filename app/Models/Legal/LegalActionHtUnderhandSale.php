<?php

namespace App\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalActionHtUnderhandSale extends Model
{
    protected $table = 'legal_action_ht_underhand_sales';

    protected $fillable = [
        'legal_action_id',
        'agreement_date',
        'buyer_name',
        'sale_value',
        'payment_method',
        'handover_date',
        'agreement_file_path',
        'proof_payment_file_path',
        'notes',
    ];

    protected $casts = [
        'agreement_date' => 'date',
        'sale_value' => 'decimal:2',
        'handover_date' => 'date',
    ];

    public function legalAction(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LegalAction::class, 'legal_action_id');
    }
}
