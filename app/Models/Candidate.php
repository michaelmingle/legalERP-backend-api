<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\LogsActivity;

class Candidate extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'organization_id',
        'full_name',
        'email',
        'role',
        'date_applied',
        'attachments',
        'stage',
        'avatar',
        'job_opening_id',
    ];

    protected $casts = [
        'date_applied' => 'date',
    ];

    public function jobOpening()
    {
        return $this->belongsTo(JobOpening::class);
    }
}