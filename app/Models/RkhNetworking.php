<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RkhNetworking extends Model
{
    protected $table = 'rkh_networking';

    protected $fillable = [
        'rkh_detail_id',
        'nama_relasi',
        'jenis_relasi',
        'potensi',
        'follow_up',
    ];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(RkhDetail::class, 'rkh_detail_id');
    }
}
