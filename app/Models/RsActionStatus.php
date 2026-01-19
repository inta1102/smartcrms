<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RsActionStatus extends Model
{
    protected $table = 'rs_action_statuses';

    protected $fillable = [
        'loan_account_id',
        'position_date',
        'status',
        'channel',
        'note',
        'updated_by',
    ];

    protected $casts = [
        'position_date' => 'date',
    ];
}
