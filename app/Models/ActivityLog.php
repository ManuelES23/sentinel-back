<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'model',
        'model_id',
        'enterprise',
        'application',
        'module',
        'submodule',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con el usuario que realizó la acción
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Crear un log de actividad
     */
    public static function log(string $action, ?string $model = null, ?int $modelId = null, ?array $oldValues = null, ?array $newValues = null)
    {
        // Intentar obtener contexto de workspace de headers o sesión
        $enterprise = request()->header('X-Enterprise-Slug') ?? session('current_enterprise_slug');
        $application = request()->header('X-Application-Slug') ?? session('current_application_slug');
        $module = request()->header('X-Module-Slug') ?? session('current_module_slug');
        $submodule = request()->header('X-Submodule-Slug') ?? session('current_submodule_slug');

        return self::create([
            'user_id' => auth()->id(),
            'action' => $action,
            'model' => $model,
            'model_id' => $modelId,
            'enterprise' => $enterprise,
            'application' => $application,
            'module' => $module,
            'submodule' => $submodule,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * Scope para filtrar por usuario
     */
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope para filtrar por acción
     */
    public function scopeByAction($query, $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope para filtrar por modelo
     */
    public function scopeByModel($query, $model)
    {
        return $query->where('model', $model);
    }

    /**
     * Scope para filtrar por rango de fechas
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
}
