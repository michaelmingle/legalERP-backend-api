<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class JobOpening extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'job_title',
        'description',
        'number_of_openings',
        'location',
        'posting_date',
        'closing_date',
    ];

    protected $casts = [
        'posting_date' => 'date',
        'closing_date' => 'date',
    ];

    public function candidates()
    {
        return $this->hasMany(Candidate::class);
    }
}