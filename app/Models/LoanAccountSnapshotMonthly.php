<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanAccountSnapshotMonthly extends Model
{
    protected $table = 'loan_account_snapshots_monthly';

    protected $fillable = [
        'snapshot_month',
        'account_no',
        'cif',
        'customer_name',
        'branch_code',
        'ao_code',
        'outstanding',
        'dpd',
        'kolek',
        'source_position_date',
    ];

    protected $casts = [
        'snapshot_month' => 'date',
        'source_position_date' => 'date',
        'outstanding' => 'decimal:2',
        'dpd' => 'integer',
        'kolek' => 'integer',
    ];
}
