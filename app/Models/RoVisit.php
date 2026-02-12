<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// app/Models/RoVisit.php
class RoVisit extends Model
{
    protected $fillable = [
        'account_no',
        'ao_code',
        'visit_date',
        'status',
        'lkh_note',
    ];

    protected $casts = [
        'visit_date' => 'date',
    ];
}

