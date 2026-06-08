<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SfPositionAssignment extends Model
{
    use HasFactory, Loggable;

    protected $table = 'sf_position_assignments';

    protected $fillable = [
        'sf_employee_id',
        'sf_position_id',
        'assignment_date',
        'assigned_at',
        'assigned_by_user_id',
        'source_device',
        'qr_code_raw',
    ];

    protected $casts = [
        'assignment_date' => 'date',
        'assigned_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(SfEmployee::class, 'sf_employee_id');
    }

    public function position()
    {
        return $this->belongsTo(SfPosition::class, 'sf_position_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
