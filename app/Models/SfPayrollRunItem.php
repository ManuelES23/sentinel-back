<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SfPayrollRunItem extends Model
{
    use HasFactory, Loggable;

    protected $fillable = [
        'sf_payroll_run_id',
        'sf_employee_id',
        'code',
        'checker_key',
        'full_name',
        'payment_frequency',
        'salary',
        'daily_rate',
        'effective_days',
        'gross_pay',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'daily_rate' => 'decimal:4',
        'effective_days' => 'decimal:2',
        'gross_pay' => 'decimal:2',
    ];

    public function payrollRun()
    {
        return $this->belongsTo(SfPayrollRun::class, 'sf_payroll_run_id');
    }

    public function employee()
    {
        return $this->belongsTo(SfEmployee::class, 'sf_employee_id');
    }
}
