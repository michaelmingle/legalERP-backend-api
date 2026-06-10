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
        'status',
        'description',
        'industry',
        'subscription_plan',
        'subscription_end_date',
    ];

    protected $casts = [
        'goals' => 'array',   // 👈 auto-encode/decode JSON
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    // Add cases relationship if you have a Case model
    public function cases()
    {
        return $this->hasMany(Cases::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)->where('status', 'active')->latest();
    }
}