<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class CaseNote extends Model
{
    use LogsActivity;
    
    protected $fillable = [
        'case_id',
        'note',
        'created_by',
    ];

    public function case()
    {
        return $this->belongsTo(Cases::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
