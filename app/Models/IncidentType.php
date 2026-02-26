<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentType extends Model
{
    use HasFactory, Loggable;

    protected $fillable = [
        'enterprise_id',
        'name',
        'code',
        'description',
        'category',
        'requires_approval',
        'affects_attendance',
        'is_paid',
        'max_days_per_year',
        'color',
        'is_active',
    ];

    protected $casts = [
        'requires_approval' => 'boolean',
        'affects_attendance' => 'boolean',
        'is_paid' => 'boolean',
        'max_days_per_year' => 'integer',
        'is_active' => 'boolean',
    ];

    // ==================== RELACIONES ====================

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(EmployeeIncident::class);
    }

    // ==================== SCOPES ====================

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    // ==================== ACCESSORS ====================

    public function getCategoryLabelAttribute(): string
    {
        return match($this->category) {
            'permission' => 'Permiso',
            'absence' => 'Falta',
            'illness' => 'Enfermedad',
            'personal_leave' => 'Permiso Personal',
            'bereavement' => 'Duelo',
            'maternity' => 'Maternidad',
            'paternity' => 'Paternidad',
            'medical' => 'Cita Médica',
            'other' => 'Otro',
            default => $this->category,
        };
    }

    // ==================== MÉTODOS ESTÁTICOS ====================

    /**
     * Crear tipos de incidencia por defecto para una empresa
     */
    public static function createDefaultsForEnterprise(int $enterpriseId): void
    {
        $defaults = [
            [
                'name' => 'Permiso con goce de sueldo',
                'code' => 'PGS',
                'category' => 'permission',
                'is_paid' => true,
                'max_days_per_year' => 3,
                'color' => '#10B981',
            ],
            [
                'name' => 'Permiso sin goce de sueldo',
                'code' => 'PSG',
                'category' => 'permission',
                'is_paid' => false,
                'max_days_per_year' => 10,
                'color' => '#F59E0B',
            ],
            [
                'name' => 'Falta injustificada',
                'code' => 'FALT',
                'category' => 'absence',
                'is_paid' => false,
                'requires_approval' => false,
                'color' => '#EF4444',
            ],
            [
                'name' => 'Incapacidad por enfermedad',
                'code' => 'ENF',
                'category' => 'illness',
                'is_paid' => true,
                'color' => '#8B5CF6',
            ],
            [
                'name' => 'Permiso por duelo',
                'code' => 'DUELO',
                'category' => 'bereavement',
                'is_paid' => true,
                'max_days_per_year' => 5,
                'color' => '#6B7280',
            ],
            [
                'name' => 'Licencia de maternidad',
                'code' => 'MAT',
                'category' => 'maternity',
                'is_paid' => true,
                'color' => '#EC4899',
            ],
            [
                'name' => 'Licencia de paternidad',
                'code' => 'PAT',
                'category' => 'paternity',
                'is_paid' => true,
                'max_days_per_year' => 5,
                'color' => '#3B82F6',
            ],
            [
                'name' => 'Cita médica',
                'code' => 'MED',
                'category' => 'medical',
                'is_paid' => true,
                'color' => '#14B8A6',
            ],
        ];

        foreach ($defaults as $type) {
            self::firstOrCreate(
                [
                    'enterprise_id' => $enterpriseId,
                    'code' => $type['code'],
                ],
                array_merge($type, [
                    'enterprise_id' => $enterpriseId,
                    'requires_approval' => $type['requires_approval'] ?? true,
                    'affects_attendance' => true,
                    'is_active' => true,
                ])
            );
        }
    }
}
