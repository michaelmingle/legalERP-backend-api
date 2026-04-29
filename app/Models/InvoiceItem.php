<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class InvoiceItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'invoice_id',
        'description',
        'type',
        'rate',
        'hours',
        'total'
    ];

    public function billing()
    {
        return $this->belongsTo(Invoice::class);
    }

    
}
