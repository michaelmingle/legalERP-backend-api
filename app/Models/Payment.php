<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'payments';
    protected $fillable = [
        'organization_id',
        'invoice_id',
        'client_id',
        'account_number',
        'bank_name',
        'amount',
        'currency',
        'method',
        'status',
        'payment_date',
        'reference_number',
        'notes',
        'received_by',
        'recorded_by',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoice() 
    {
        return $this->belongsTo(Invoice::class);
    }

    public function client() 
    {
        return $this->belongsTo(Client::class);
    }
}
