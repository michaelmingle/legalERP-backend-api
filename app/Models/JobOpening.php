<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\LogsActivity;

class JobOpening extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'organization_id',
        'job_title',
        'description',
        'number_of_openings',
        'location',
        'posting_date',
        'closing_date',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    protected $casts = [
        'posting_date' => 'date',
        'closing_date' => 'date',
    ];

    public function candidates()
    {
        return $this->hasMany(Candidate::class);
    }
}