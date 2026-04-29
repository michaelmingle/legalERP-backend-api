<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
    use LogsActivity;
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'organization_id',
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'mobile',
        'photo_url',
        'gender',
        'date_of_birth',
        'job_title',
        'status',
        'role',
        'address',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = ['is_online'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',    
            'last_activity_at' => 'datetime',
            'is_online' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function isOnline(): bool
    {
        return $this->last_activity_at && $this->last_activity_at->gt(now()->subMinutes(5));
    }

    // public function getIsOnlineAttribute(): bool
    // {
    //     return $this->isOnline();
    // }

    public function getIsOnlineAttribute(): bool
    {
        return $this->last_activity_at 
            && $this->last_activity_at->gt(now()->subMinutes(5));
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function client()
{
    return $this->hasOne(Client::class);
}
}
