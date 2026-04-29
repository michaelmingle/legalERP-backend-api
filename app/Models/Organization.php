<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'name',
        'address',
        'phone',
        'organisation_email',
        'website',
        'team',
        'goals',
    ];

    protected $casts = [
        'goals' => 'array',   // 👈 auto-encode/decode JSON
    ];  

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
