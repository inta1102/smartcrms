<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShmCheckRequestFile extends Model
{
    protected $table = 'shm_check_request_files';

    protected $fillable = [
        'request_id','type','file_path','original_name','uploaded_by','uploaded_at','notes'
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(ShmCheckRequest::class, 'request_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
