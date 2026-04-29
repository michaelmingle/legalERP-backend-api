<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class InvoiceDiscount extends Model
{
    use LogsActivity;

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
