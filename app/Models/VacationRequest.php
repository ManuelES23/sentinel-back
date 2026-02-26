<?php

namespace App\Models;

use App\Traits\Loggable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VacationRequest extends Model
{
    use HasFactory, Loggable, SoftDeletes;

    protected $fillable = [
        'employee_id',
        'enterprise_id',
        'start_date',
        'end_date',
        'days_requested',
        'status',
        'reason',
        'rejection_reason',
        'approved_by',
        'approved_at',
        'vacation_year',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
        'days_requested' => 'integer',
        'vacation_year' => 'integer',
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

    public function scopeForYear($query, int $year)
    {
        return $query->where('vacation_year', $year);
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

    // ==================== ACCESSORS ====================

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pendiente',
            'approved' => 'Aprobada',
            'rejected' => 'Rechazada',
            'cancelled' => 'Cancelada',
            default => $this->status,
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'yellow',
            'approved' => 'green',
            'rejected' => 'red',
            'cancelled' => 'gray',
            default => 'gray',
        };
    }

    // ==================== MÉTODOS ====================

    public function approve(User $approver): bool
    {
        $this->update([
            'status' => 'approved',
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        // Actualizar balance de vacaciones
        $this->updateVacationBalance();

        return true;
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
        if ($this->status === 'approved') {
            // Revertir balance si ya estaba aprobada
            $this->revertVacationBalance();
        }

        return $this->update(['status' => 'cancelled']);
    }

    protected function updateVacationBalance(): void
    {
        $balance = VacationBalance::firstOrCreate(
            [
                'employee_id' => $this->employee_id,
                'year' => $this->vacation_year,
            ],
            [
                'entitled_days' => 0,
                'used_days' => 0,
                'pending_days' => 0,
                'carried_over' => 0,
            ]
        );

        $balance->increment('used_days', $this->days_requested);
        $balance->decrement('pending_days', $this->days_requested);
    }

    protected function revertVacationBalance(): void
    {
        $balance = VacationBalance::where('employee_id', $this->employee_id)
            ->where('year', $this->vacation_year)
            ->first();

        if ($balance) {
            $balance->decrement('used_days', $this->days_requested);
        }
    }
}
