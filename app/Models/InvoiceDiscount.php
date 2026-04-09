<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceDiscount extends Model
{
    protected $fillable = [
        'invoice_id',
        'name',
        'type',
        'value'
    ];

    public function billing()
    {
        return $this->belongsTo(Invoice::class);
    }
}
