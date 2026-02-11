<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterTujuanKegiatan extends Model
{
    protected $table = 'master_tujuan_kegiatan';

    protected $fillable = ['jenis_code','code','label','is_active','sort'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(MasterJenisKegiatan::class, 'jenis_code', 'code');
    }
}
