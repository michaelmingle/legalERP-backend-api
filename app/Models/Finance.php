<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;

class Finance extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'case_id',
        'amount',
        'type', // e.g., 'income' or 'expense'
        'description',
        'date',
    ];

    public function case()
    {
        return $this->belongsTo(Cases::class);
    }
}
