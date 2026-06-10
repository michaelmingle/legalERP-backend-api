<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payroll extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'employee_id',
        'payroll_no',
        'period_month',
        'period_year',
        'basic_salary',
        'allowance',
        'deduction',
        'tax',
        'net_pay',
        'status',
        'processed_at',
        'processed_by',
        'notes',
    ];

    protected $casts = [
        'period_month' => 'integer',
        'period_year'  => 'integer',
        'basic_salary' => 'decimal:2',
        'allowance'    => 'decimal:2',
        'deduction'    => 'decimal:2',
        'tax'          => 'decimal:2',
        'net_pay'      => 'decimal:2',
        'processed_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function processor()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** Compute net pay from current numeric fields. */
    public static function calculateNet(array $attrs): float
    {
        $basic     = (float) ($attrs['basic_salary'] ?? 0);
        $allowance = (float) ($attrs['allowance']    ?? 0);
        $deduction = (float) ($attrs['deduction']    ?? 0);
        $tax       = (float) ($attrs['tax']          ?? 0);
        return round(($basic + $allowance) - ($deduction + $tax), 2);
    }
}
