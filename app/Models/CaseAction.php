<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaseAction extends Model
{
    protected $table = 'case_actions';

    protected $fillable = [
        'npl_case_id',
        'user_id',
        'source_system',
        'source_ref_id',
        'action_at',
        'action_type',
        'description',
        'result',
        'proof_url',
        'meta',
        'next_action',
        'next_action_due',
    ];

    protected $casts = [
    'action_at' => 'datetime',
    'next_action_due' => 'date',
    'meta' => 'array',
    ];


    public function nplCase()
    {
        return $this->belongsTo(\App\Models\NplCase::class, 'npl_case_id');
    }

    public function agenda()
    {
        return $this->belongsTo(\App\Models\AoAgenda::class, 'ao_agenda_id');
    }

    public function proofs()
    {
        return $this->hasMany(
            \App\Models\CaseActionProof::class,
            'case_action_id'
        );
    }


}
