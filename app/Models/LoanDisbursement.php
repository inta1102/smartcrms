<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanDisbursement extends Model
{
    protected $fillable = [
        'account_no','ao_code','user_id',
        'disb_date','period','amount',
        'cif','customer_name',
        'source_file','import_batch_id',
    ];

    protected $casts = [
        'disb_date' => 'date',
        'period' => 'date',
    ];
}
