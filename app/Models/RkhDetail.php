<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RkhDetail extends Model
{
    protected $table = 'rkh_details';

    protected $fillable = [
        'rkh_id',
        'jam_mulai',
        'jam_selesai',
        'nasabah_id',
        'nama_nasabah',
        'kolektibilitas',
        'jenis_kegiatan',
        'tujuan_kegiatan',
        'area',
        'catatan',
        'account_no',
    ];

    protected $casts = [
        // time columns disimpan sebagai string "HH:MM:SS" di DB
        'nasabah_id' => 'integer',
    ];


    public function header(): BelongsTo
    {
        return $this->belongsTo(\App\Models\RkhHeader::class, 'rkh_header_id');
    }


    public function lkh(): HasOne
    {
        return $this->hasOne(LkhReport::class, 'rkh_detail_id');
    }

    public function networking(): HasOne
    {
        return $this->hasOne(RkhNetworking::class, 'rkh_detail_id');
    }
    
    public function visitLogs()
    {
        return $this->hasMany(\App\Models\RkhVisitLog::class, 'rkh_detail_id');
    }

}
