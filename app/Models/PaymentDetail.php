<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class PaymentDetail extends Model
{
    use LogsActivity;

    protected $fillable = [
        'bank_name',
        'account_name',
        'account_number'
    ];
}
