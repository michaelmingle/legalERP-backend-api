<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
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
