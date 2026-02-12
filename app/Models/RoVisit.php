<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoVisit extends Model
{
    protected $table = 'ro_visits';

    protected $fillable = [
        'user_id',
        'account_no',
        'ao_code',
        'visit_date',
        'status',
        'source',
        'lkh_note',
    ];

    protected $casts = [
        'visit_date' => 'date',
    ];
}
