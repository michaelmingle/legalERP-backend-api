<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class Goals extends Model
{
    use LogsActivity;

    protected $fillable = [
        'case_id',
        'description',
        'status',
        'due_date',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public function case()
    {
        return $this->belongsTo(Cases::class);
    }
}
