<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Un periodo de vacaciones del historial que captura RH (sin cadena de aprobación). */
class VacationHistoryEntry extends Model
{
    use Loggable, SoftDeletes;

    public const ORIGEN_MANUAL = 'manual';

    public const ORIGEN_IMPORTACION = 'importacion';

    protected $table = 'vacation_history';

    protected $fillable = [
        'employee_id', 'vacation_year', 'start_date', 'end_date', 'days', 'notes', 'source', 'import_file', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'vacation_year' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'days' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
