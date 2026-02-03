<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanInstallment extends Model
{
    protected $fillable = [
        'account_no','ao_code','user_id',
        'period','due_date','due_amount',
        'paid_date','paid_amount',
        'is_paid','is_paid_ontime','days_late',
        'source_file','import_batch_id',
    ];

    protected $casts = [
        'period' => 'date',
        'due_date' => 'date',
        'paid_date' => 'date',
        'is_paid' => 'boolean',
        'is_paid_ontime' => 'boolean',
    ];
}
