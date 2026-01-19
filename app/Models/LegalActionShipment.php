<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalActionShipment extends Model
{
    protected $fillable = [
        'legal_action_id',
        'delivery_channel',
        'expedition_name',
        'receipt_no',
        'notes',
        'receipt_path',
        'receipt_original',
        'uploaded_by',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(LegalAction::class, 'legal_action_id');
    }
}
