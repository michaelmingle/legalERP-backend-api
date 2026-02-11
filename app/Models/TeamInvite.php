<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeamInvite extends Model
{
    protected $fillable = [
        'organization_id',
        'email',
        'status',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
