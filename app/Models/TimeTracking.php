<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class TimeTracking extends Model
{
    use LogsActivity;

    protected $fillable = [
        'case_id',
        'user_id',
        'case_stage',  // New field for case stage/level
        'description',
        'hours',
        'date',
        'status'
    ];

    protected $casts = [
        'date' => 'date',
        'hours' => 'decimal:2'
    ];

    public function case()
    {
        return $this->belongsTo(Cases::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Get stage name with level
    public function getStageNameAttribute()
    {
        $stages = [
            'initial_opening' => ['level' => 1, 'name' => 'Initial Opening'],
            'case_assessment' => ['level' => 2, 'name' => 'Case Assessment'],
            'evidence_gathering' => ['level' => 3, 'name' => 'Evidence Gathering'],
            'legal_research' => ['level' => 4, 'name' => 'Legal Research'],
            'initial_filing' => ['level' => 5, 'name' => 'Initial Filing'],
            'discovery' => ['level' => 6, 'name' => 'Discovery'],
            'motions_practice' => ['level' => 7, 'name' => 'Motions Practice'],
            'pre_trial' => ['level' => 8, 'name' => 'Pre-Trial'],
            'trial' => ['level' => 9, 'name' => 'Trial'],
            'resolution' => ['level' => 10, 'name' => 'Resolution'],
        ];

        return $stages[$this->case_stage] ?? ['level' => 0, 'name' => 'Unknown'];
    }
}