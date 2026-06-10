<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'organization_id',
        'plan_name',
        'amount',
        'billing_cycle',
        'status',
        'is_active',
        'start_date',
        'end_date',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
