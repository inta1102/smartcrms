<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AoMapping extends Model
{
    protected $fillable = ['employee_code','ao_code','is_active'];
}
