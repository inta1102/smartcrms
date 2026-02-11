<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterJenisKegiatan extends Model
{
    protected $table = 'master_jenis_kegiatan';

    protected $fillable = ['code','label','is_active','sort'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    public function tujuan(): HasMany
    {
        return $this->hasMany(MasterTujuanKegiatan::class, 'jenis_code', 'code')
            ->where('is_active', true)
            ->orderBy('sort');
    }
}
