<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SfPayrollRun extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $fillable = [
        'enterprise_id',
        'start_date',
        'end_date',
        'source_file',
        'total_employees',
        'total_gross_pay',
        'generated_by_user_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_employees' => 'integer',
        'total_gross_pay' => 'decimal:2',
    ];

    public function enterprise()
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function generatedBy()
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    public function items()
    {
        return $this->hasMany(SfPayrollRunItem::class)->orderBy('full_name');
    }
}
