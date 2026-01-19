<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LegalAdminChecklist extends Model
{
    protected $fillable = [
        'legal_action_id','check_code','check_label','is_required','sort_order',
        'is_checked','checked_by','checked_at','notes'
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_checked'  => 'boolean',
        'checked_at'  => 'datetime',
    ];

    public function action()
    {
        return $this->belongsTo(LegalAction::class, 'legal_action_id');
    }

    public function checker()
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function legalAction()
    {
        return $this->belongsTo(\App\Models\LegalAction::class, 'legal_action_id');
    }
}
