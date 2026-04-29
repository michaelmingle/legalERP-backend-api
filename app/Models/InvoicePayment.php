<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class InvoicePayment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'invoice_id',
        'amount',
        'payment_date',
        'payment_method',
        'reference'
    ];

    public function billing()
    {
        return $this->belongsTo(Invoice::class);
    }
}
