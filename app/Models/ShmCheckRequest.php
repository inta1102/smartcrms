<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShmCheckRequest extends Model
{
    protected $table = 'shm_check_requests';

    // status constants
    public const STATUS_SUBMITTED        = 'SUBMITTED';
    public const STATUS_SENT_TO_NOTARY   = 'SENT_TO_NOTARY';
    public const STATUS_WAITING_SP_SK    = 'WAITING_SP_SK';
    public const STATUS_SP_SK_UPLOADED   = 'SP_SK_UPLOADED';
    public const STATUS_SIGNED_UPLOADED  = 'SIGNED_UPLOADED';
    public const STATUS_HANDED_TO_SAD    = 'HANDED_TO_SAD';
    public const STATUS_SENT_TO_BPN      = 'SENT_TO_BPN';
    public const STATUS_RESULT_UPLOADED  = 'RESULT_UPLOADED';
    public const STATUS_CLOSED           = 'CLOSED';
    public const STATUS_REJECTED         = 'REJECTED';

    protected $fillable = [
        'request_no','requested_by','branch_code','ao_code',
        'debtor_name','debtor_phone','collateral_address','certificate_no','notary_name',
        'status','submitted_at','sent_to_notary_at','sp_sk_uploaded_at','signed_uploaded_at',
        'handed_to_sad_at','sent_to_bpn_at','result_uploaded_at','closed_at','notes',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'sent_to_notary_at' => 'datetime',
        'sp_sk_uploaded_at' => 'datetime',
        'signed_uploaded_at' => 'datetime',
        'handed_to_sad_at' => 'datetime',
        'sent_to_bpn_at' => 'datetime',
        'result_uploaded_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(ShmCheckRequestFile::class, 'request_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ShmCheckRequestLog::class, 'request_id');
    }

    public function scopeVisibleFor($q, $user)
    {
        $rv = strtoupper(trim($user->roleValue() ?? ''));
        if (in_array($rv, ['KSA','KBO','SAD'], true)) return $q;

        return $q->where('requested_by', $user->id);
    }

    public const NOTARIES = [
        'Justicia',
        'Imam',
        'Ricky',
    ];

}
