<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeIncident extends Model
{
    use HasFactory, SoftDeletes, Loggable;

    protected $fillable = [
        'employee_id',
        'enterprise_id',
        'incident_type_id',
        'start_date',
        'end_date',
        'days',
        'start_time',
        'end_time',
        'status',
        'reason',
        'rejection_reason',
        'document_path',
        'approved_by',
        'approved_at',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'start_time' => 'datetime:H:i',
        'end_time' => 'datetime:H:i',
        'days' => 'decimal:1',
        'approved_at' => 'datetime',
    ];

    // ==================== RELACIONES ====================

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(Enterprise::class);
    }

    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ==================== SCOPES ====================

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->where(function ($q) use ($startDate, $endDate) {
            $q->whereBetween('start_date', [$startDate, $endDate])
              ->orWhereBetween('end_date', [$startDate, $endDate])
              ->orWhere(function ($q2) use ($startDate, $endDate) {
                  $q2->where('start_date', '<=', $startDate)
                     ->where('end_date', '>=', $endDate);
              });
        });
    }

    public function scopeByType($query, int $typeId)
    {
        return $query->where('incident_type_id', $typeId);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->whereHas('incidentType', function ($q) use ($category) {
            $q->where('category', $category);
        });
    }

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Pendiente',
            'approved' => 'Aprobada',
            'rejected' => 'Rechazada',
            'cancelled' => 'Cancelada',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'yellow',
            'approved' => 'green',
            'rejected' => 'red',
            'cancelled' => 'gray',
            default => 'gray',
        };
    }

    public function getIsPartialDayAttribute(): bool
    {
        return $this->start_time !== null && $this->end_time !== null;
    }

    public function getDocumentUrlAttribute(): ?string
    {
        return $this->document_path 
            ? asset('storage/' . $this->document_path) 
            : null;
    }

    // ==================== MÉTODOS ====================

    public function approve(User $approver): bool
    {
        return $this->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    public function reject(User $approver, string $reason): bool
    {
        return $this->update([
            'status' => 'rejected',
            'approved_by' => $approver->id,
            'approved_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function cancel(): bool
    {
        return $this->update(['status' => 'cancelled']);
    }

    /**
     * Verificar si el empleado puede solicitar este tipo de incidencia
     * (considerando límite anual)
     */
    public function canEmployeeRequest(): array
    {
        $type = $this->incidentType;
        
        if (!$type->max_days_per_year) {
            return ['allowed' => true, 'message' => null];
        }

        $usedDays = self::where('employee_id', $this->employee_id)
            ->where('incident_type_id', $this->incident_type_id)
            ->whereYear('start_date', now()->year)
            ->whereIn('status', ['approved', 'pending'])
            ->sum('days');

        $available = $type->max_days_per_year - $usedDays;

        if ($this->days > $available) {
            return [
                'allowed' => false,
                'message' => "Solo tiene {$available} días disponibles de {$type->name} este año.",
            ];
        }

        return ['allowed' => true, 'message' => null];
    }
}
