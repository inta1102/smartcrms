<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    protected $table = 'import_logs';

    protected $fillable = [
        'module',
        'position_date',
        'file_name',
        'rows_total',
        'rows_inserted',
        'rows_updated',
        'rows_skipped',
        'status',
        'message',
        'imported_by',
    ];

    protected $casts = [
        'position_date' => 'date',
    ];

    public function importer()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
