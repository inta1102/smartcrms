<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LkhReport extends Model
{
    protected $table = 'lkh_reports';

    protected $fillable = [
        'rkh_detail_id',
        'is_visited',
        'hasil_kunjungan',
        'respon_nasabah',
        'tindak_lanjut',
        'evidence_path',
    ];

    protected $casts = [
        'is_visited' => 'boolean',
    ];

    public function detail(): BelongsTo
    {
        return $this->belongsTo(RkhDetail::class, 'rkh_detail_id');
    }
}
