<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number',
        'client_id',
        'case_id',
        'lawyer_id',
        'issue_date',
        'due_date',
        'subtotal',
        'discount',
        'vat',
        'total',
        'status'
    ];

    protected $with = [
        'items',
        'payments',
        'client',
        'case',
        'lawyer',
    ];

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function case()
    {
        return $this->belongsTo(Cases::class);
    }

    public function lawyer()
    {
        return $this->belongsTo(User::class);
    }
}
