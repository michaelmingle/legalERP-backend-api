<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cases extends Model
{
    protected $fillable = [
        'case_number',
        'organization_id',
        'case_type_id',
        'case_type',
        'client_id',
        'case_name',
        'note',
        'status',
        'priority',
        'confidentiality',
        'assigned_to',
        'document',
        'supervisor',
        'billing_method',
        'case_start_date',
        'expected_resolution_date',
        'next_hearing_date',
        'next_followup_date',
        'rate',
        'deposit',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function caseType()
    {
        return $this->belongsTo(CaseType::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervisor');
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
