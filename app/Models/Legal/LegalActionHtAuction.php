<?php

namespace App\Models\Legal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalActionHtAuction extends Model
{
    protected $table = 'legal_action_ht_auctions';

    public const RESULT_LAKU      = 'laku';
    public const RESULT_TIDAK_LAKU = 'tidak_laku';
    public const RESULT_BATAL     = 'batal';
    public const RESULT_TUNDA     = 'tunda';

    protected $fillable = [
        'legal_action_id',
        'attempt_no',
        'kpknl_office',
        'registration_no',
        'limit_value',
        'auction_date',
        'auction_result',
        'sold_value',
        'winner_name',
        'settlement_date',
        'notes',
        'risalah_file_path',
    ];


    protected $casts = [
        'attempt_no' => 'integer',
        'limit_value' => 'decimal:2',
        'sold_value' => 'decimal:2',
        'auction_date' => 'date',
        'settlement_date' => 'date',
    ];

    public function legalAction(): BelongsTo
    {
        return $this->belongsTo(\App\Models\LegalAction::class, 'legal_action_id');
    }
    
}
