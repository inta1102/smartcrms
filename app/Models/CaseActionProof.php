<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaseActionProof extends Model
{
    protected $table = 'case_action_proofs';

    protected $fillable = [
        'case_action_id',
        'uploaded_by',
        'file_path',
        'original_name',
        'mime',
        'size',
        'note',
    ];

    public function action()
    {
        return $this->belongsTo(CaseAction::class, 'case_action_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
