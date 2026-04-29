<?php
// app/Models/Invoice.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'organization_id',  // Add this
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

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'vat' => 'decimal:2',
        'total' => 'decimal:2',
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
        return $this->hasMany(Payment::class);
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
        return $this->belongsTo(User::class, 'lawyer_id');
    }
    
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}