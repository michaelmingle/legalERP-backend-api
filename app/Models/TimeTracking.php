<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TimeTracking extends Model
{
    protected $fillable = [
        'case_id',
        'description',
        'hours',
        'date',
        'status'
    ];

     public function case()
    {
        return $this->belongsTo(Cases::class);
    } 
}
