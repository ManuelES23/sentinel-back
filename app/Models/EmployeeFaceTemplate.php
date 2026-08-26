<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeFaceTemplate extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    /**
     * Atributos que Loggable nunca debe copiar a ActivityLog.old_values /
     * new_values. El embedding facial es dato biométrico sensible: si se
     * loguea, revocar/purgar la plantilla ya no lo elimina realmente (queda
     * una copia sin control de retención en activity_logs).
     */
    protected array $loggableExcept = ['embedding'];

    protected $fillable = [
        'employee_id',
        'embedding',
        'photo_path',
        'model_version',
        'enrolled_by_user_id',
        'enrolled_at',
        'consent_signed_at',
        'consent_document_path',
        'status',
        'revoked_at',
    ];

    protected $casts = [
        'embedding' => 'array',
        'enrolled_at' => 'datetime',
        'consent_signed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function enrolledBy()
    {
        return $this->belongsTo(User::class, 'enrolled_by_user_id');
    }
}
