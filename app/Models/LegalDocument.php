<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalDocument extends Model
{
    protected $table = 'legal_documents';
    public const DOC_SOMASI_SHIPPING_RECEIPT   = 'somasi_shipping_receipt';
    public const DOC_SOMASI_TRACKING_SCREENSHOT = 'somasi_tracking_screenshot';
    public const DOC_SOMASI_POD               = 'somasi_pod';
    public const DOC_SOMASI_RETURN_PROOF      = 'somasi_return_proof';

    protected $fillable = [
        'legal_case_id',
        'legal_action_id',
        'doc_type',
        'title',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'hash_sha256',
        'uploaded_by',
        'uploaded_at',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function action(): BelongsTo
    {
        return $this->belongsTo(LegalAction::class, 'legal_action_id');
    }

    public function legalCase(): BelongsTo
    {
        return $this->belongsTo(LegalCase::class, 'legal_case_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getDocTypeLabelAttribute(): string
    {
        return match ($this->doc_type) {
            self::DOC_SOMASI_SHIPPING_RECEIPT => 'Bukti Kirim Somasi (Resi)',
            self::DOC_SOMASI_TRACKING_SCREENSHOT => 'Screenshot Tracking',
            self::DOC_SOMASI_POD => 'POD / Tanda Terima',
            self::DOC_SOMASI_RETURN_PROOF => 'Return / Gagal Kirim',
            default => $this->doc_type,
        };
    }

}
