<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Events\VacationRequestUpdated;
use App\Models\VacationRequest;
use App\Models\VacationBalance;
use App\Services\ApprovalNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    /**
     * Obtener perfil del usuario autenticado
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Cargar el empleado vinculado con sus relaciones
        $user->load(['employee' => function ($query) {
            $query->with([
                'department:id,name',
                'position:id,name',
                'workSchedule:id,name,monday_start,monday_end,tuesday_start,tuesday_end,wednesday_start,wednesday_end,thursday_start,thursday_end,friday_start,friday_end,saturday_start,saturday_end,sunday_start,sunday_end',
                'enterprise:id,name,slug,logo',
            ]);
        }]);

        $employee = $user->employee;
        
        // Obtener balance de vacaciones y solicitudes si tiene empleado
        $vacationData = null;
        if ($employee) {
            $currentYear = date('Y');
            
            // Calcular años de servicio completos
            $yearsOfService = $employee->hire_date 
                ? (int)$employee->hire_date->diffInYears(now()) 
                : 0;
            
            // Días acumulados según LFT (suma de todos los años: 12+14+16=42 para 3 años)
            $accumulatedDays = VacationBalance::calculateAccumulatedDays($yearsOfService);
            
            // Obtener balance de vacaciones del año actual (configurado en RH)
            $balance = VacationBalance::where('employee_id', $employee->id)
                ->where('year', $currentYear)
                ->first();
            
            // Solicitudes de vacaciones del año actual
            $vacationRequests = VacationRequest::where('employee_id', $employee->id)
                ->where('vacation_year', $currentYear)
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();
            
            // Solicitudes pendientes
            $pendingRequests = VacationRequest::where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->count();
            
            // Obtener fechas bloqueadas (solicitudes pending o approved)
            $blockedDates = VacationRequest::where('employee_id', $employee->id)
                ->whereIn('status', ['pending', 'approved'])
                ->where('end_date', '>=', now()->format('Y-m-d'))
                ->get(['start_date', 'end_date', 'status'])
                ->map(function ($req) {
                    return [
                        'start_date' => $req->start_date->format('Y-m-d'),
                        'end_date' => $req->end_date->format('Y-m-d'),
                        'status' => $req->status,
                    ];
                });
            
            // Formatear antigüedad como texto
            $months = $employee->hire_date 
                ? (int)($employee->hire_date->diffInMonths(now()) % 12)
                : 0;
            $seniority = $yearsOfService > 0 
                ? "{$yearsOfService} año(s), {$months} mes(es)"
                : "{$months} mes(es)";
            
            // Próximo aniversario
            $nextAnniversary = null;
            if ($employee->hire_date) {
                $anniversary = $employee->hire_date->copy()->year(now()->year);
                if ($anniversary->isPast()) {
                    $anniversary->addYear();
                }
                $nextAnniversary = $anniversary->format('Y-m-d');
            }
            
            // Calcular días acumulados con ajuste (misma lógica que RH)
            $adjustmentDays = $balance?->adjustment_days ?? 0;
            $usedDays = $balance?->used_days ?? 0;
            $pendingDays = $balance?->pending_days ?? 0;
            $carriedOver = $balance?->carried_over ?? 0;
            
            // Total acumulado con ajustes = días LFT + carried_over + ajuste
            $totalAccumulated = $accumulatedDays + $carriedOver + $adjustmentDays;
            
            // Días disponibles = total acumulado - usados - pendientes
            $availableDays = $totalAccumulated - $usedDays - $pendingDays;
            
            $vacationData = [
                'balance' => [
                    'accumulated_days' => $totalAccumulated, // Total acumulado con ajustes (ej: 33)
                    'raw_accumulated_days' => $accumulatedDays, // Sin ajustes (ej: 42)
                    'entitled_days' => $balance?->entitled_days ?? VacationBalance::calculateEntitledDays($yearsOfService), // Días este año
                    'available_days' => $availableDays, // Días disponibles calculados correctamente
                    'used_days' => $usedDays,
                    'pending_days' => $pendingDays,
                    'carried_over' => $carriedOver,
                    'adjustment_days' => $adjustmentDays,
                    'years_of_service' => $yearsOfService,
                    'seniority' => $seniority,
                    'hire_date' => $employee->hire_date?->format('Y-m-d'),
                    'next_anniversary' => $nextAnniversary,
                    'year' => $currentYear,
                ],
                'requests' => $vacationRequests,
                'pending_count' => $pendingRequests,
                'blocked_dates' => $blockedDates,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'photo' => $user->photo ? asset('storage/' . $user->photo) : null,
                    'role' => $user->role,
                    'created_at' => $user->created_at,
                ],
                'employee' => $employee ? [
                    'id' => $employee->id,
                    'employee_number' => $employee->employee_number,
                    'full_name' => $employee->full_name,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'second_last_name' => $employee->second_last_name,
                    'email' => $employee->email,
                    'phone' => $employee->phone,
                    'mobile' => $employee->mobile,
                    'photo' => $employee->photo_url,
                    'birth_date' => $employee->birth_date?->format('Y-m-d'),
                    'hire_date' => $employee->hire_date?->format('Y-m-d'),
                    'status' => $employee->status,
                    'contract_type' => $employee->contract_type,
                    'department' => $employee->department ? [
                        'id' => $employee->department->id,
                        'name' => $employee->department->name,
                    ] : null,
                    'position' => $employee->position ? [
                        'id' => $employee->position->id,
                        'name' => $employee->position->name,
                    ] : null,
                    'work_schedule' => $employee->workSchedule ? [
                        'id' => $employee->workSchedule->id,
                        'name' => $employee->workSchedule->name,
                        'monday' => ['start' => $employee->workSchedule->monday_start, 'end' => $employee->workSchedule->monday_end],
                        'tuesday' => ['start' => $employee->workSchedule->tuesday_start, 'end' => $employee->workSchedule->tuesday_end],
                        'wednesday' => ['start' => $employee->workSchedule->wednesday_start, 'end' => $employee->workSchedule->wednesday_end],
                        'thursday' => ['start' => $employee->workSchedule->thursday_start, 'end' => $employee->workSchedule->thursday_end],
                        'friday' => ['start' => $employee->workSchedule->friday_start, 'end' => $employee->workSchedule->friday_end],
                        'saturday' => ['start' => $employee->workSchedule->saturday_start, 'end' => $employee->workSchedule->saturday_end],
                        'sunday' => ['start' => $employee->workSchedule->sunday_start, 'end' => $employee->workSchedule->sunday_end],
                    ] : null,
                    'enterprise' => $employee->enterprise ? [
                        'id' => $employee->enterprise->id,
                        'name' => $employee->enterprise->name,
                        'slug' => $employee->enterprise->slug,
                        'logo' => $employee->enterprise->logo ? asset('storage/' . $employee->enterprise->logo) : null,
                    ] : null,
                ] : null,
                'vacation' => $vacationData,
            ],
        ]);
    }

    /**
     * Actualizar perfil del usuario
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'photo' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048',
        ]);

        // Manejar subida de foto
        if ($request->hasFile('photo')) {
            // Eliminar foto anterior si existe
            if ($user->photo) {
                Storage::disk('public')->delete($user->photo);
            }
            $validated['photo'] = $request->file('photo')->store('users/photos', 'public');
        }

        $user->update($validated);

        return response()->json([
            'status' => 'success',
            'message' => 'Perfil actualizado correctamente',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'photo' => $user->photo ? asset('storage/' . $user->photo) : null,
            ],
        ]);
    }

    /**
     * Cambiar contraseña
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'La contraseña actual es incorrecta',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Contraseña actualizada correctamente',
        ]);
    }

    /**
     * Solicitar vacaciones desde el perfil
     */
    public function requestVacation(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes un empleado vinculado para solicitar vacaciones',
            ], 400);
        }

        $validated = $request->validate([
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:500',
        ]);

        // Calcular días solicitados (excluyendo fines de semana)
        $startDate = \Carbon\Carbon::parse($validated['start_date']);
        $endDate = \Carbon\Carbon::parse($validated['end_date']);
        $daysRequested = 0;
        
        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            if (!$date->isWeekend()) {
                $daysRequested++;
            }
        }

        // Verificar balance disponible
        $currentYear = date('Y');
        $balance = VacationBalance::where('employee_id', $employee->id)
            ->where('year', $currentYear)
            ->first();

        // Calcular días disponibles con la misma lógica que el perfil
        $yearsOfService = $employee->hire_date 
            ? (int)$employee->hire_date->diffInYears(now()) 
            : 0;
        $accumulatedDays = VacationBalance::calculateAccumulatedDays($yearsOfService);
        
        $adjustmentDays = $balance?->adjustment_days ?? 0;
        $usedDays = $balance?->used_days ?? 0;
        $pendingDays = $balance?->pending_days ?? 0;
        $carriedOver = $balance?->carried_over ?? 0;
        
        $totalAccumulated = $accumulatedDays + $carriedOver + $adjustmentDays;
        $availableDays = $totalAccumulated - $usedDays - $pendingDays;

        if ($availableDays < $daysRequested) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes suficientes días de vacaciones disponibles. Disponibles: ' . 
                    $availableDays . ', Solicitados: ' . $daysRequested,
            ], 400);
        }

        // Verificar que no haya solicitudes pendientes o aprobadas que se traslapen
        $overlapping = VacationRequest::where('employee_id', $employee->id)
            ->whereIn('status', ['pending', 'approved'])
            ->where(function ($query) use ($validated) {
                $query->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                    ->orWhereBetween('end_date', [$validated['start_date'], $validated['end_date']])
                    ->orWhere(function ($q) use ($validated) {
                        $q->where('start_date', '<=', $validated['start_date'])
                            ->where('end_date', '>=', $validated['end_date']);
                    });
            })
            ->exists();

        if ($overlapping) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ya tienes una solicitud de vacaciones que se traslapa con estas fechas',
            ], 400);
        }

        // Crear solicitud
        $vacationRequest = VacationRequest::create([
            'employee_id' => $employee->id,
            'enterprise_id' => $employee->enterprise_id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'days_requested' => $daysRequested,
            'status' => 'pending',
            'reason' => $validated['reason'] ?? null,
            'vacation_year' => $currentYear,
            'created_by' => $user->id,
        ]);

        // Actualizar días pendientes en el balance (crear si no existe)
        if ($balance) {
            $balance->increment('pending_days', $daysRequested);
        } else {
            // Crear balance si no existe
            VacationBalance::create([
                'employee_id' => $employee->id,
                'year' => $currentYear,
                'entitled_days' => VacationBalance::calculateEntitledDays($yearsOfService),
                'used_days' => 0,
                'pending_days' => $daysRequested,
                'carried_over' => 0,
            ]);
        }

        // Broadcast para tiempo real en RH
        $vacationRequest->load('employee:id,first_name,last_name,employee_number');
        broadcast(new VacationRequestUpdated(
            'created',
            $vacationRequest->toArray(),
            $employee->enterprise?->slug ?? 'grupoesplendido',
            'administration',
            'rh'
        ))->toOthers();

        // Notificar a los aprobadores del departamento correspondiente
        $employee->load('position', 'department');
        ApprovalNotificationService::notifyApprovers(
            'vacation_requests',
            $employee,
            'Nueva solicitud de vacaciones',
            $employee->full_name . ' ha solicitado ' . $daysRequested . ' día(s) de vacaciones del ' .
                $startDate->format('d/m/Y') . ' al ' . $endDate->format('d/m/Y'),
            '/profile?tab=approvals',
            'vacation'
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Solicitud de vacaciones enviada correctamente',
            'data' => $vacationRequest,
        ]);
    }

    /**
     * Cancelar solicitud de vacaciones
     */
    public function cancelVacationRequest(Request $request, VacationRequest $vacationRequest): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee || $vacationRequest->employee_id !== $employee->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes permiso para cancelar esta solicitud',
            ], 403);
        }

        if ($vacationRequest->status !== 'pending') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo puedes cancelar solicitudes pendientes',
            ], 400);
        }

        // Restaurar días pendientes en el balance
        $balance = VacationBalance::where('employee_id', $employee->id)
            ->where('year', $vacationRequest->vacation_year)
            ->first();

        if ($balance) {
            $balance->decrement('pending_days', $vacationRequest->days_requested);
        }

        $vacationRequest->update(['status' => 'cancelled']);

        // Broadcast para tiempo real en RH
        broadcast(new VacationRequestUpdated(
            'cancelled',
            $vacationRequest->load('employee:id,first_name,last_name,employee_number')->toArray(),
            $employee->enterprise?->slug ?? 'grupoesplendido',
            'administration',
            'rh'
        ))->toOthers();

        return response()->json([
            'status' => 'success',
            'message' => 'Solicitud de vacaciones cancelada',
        ]);
    }

    /**
     * Obtener historial de vacaciones del empleado
     */
    public function vacationHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (!$employee) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes un empleado vinculado',
            ], 400);
        }

        $requests = VacationRequest::where('employee_id', $employee->id)
            ->with(['approver:id,name'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $balances = VacationBalance::where('employee_id', $employee->id)
            ->orderBy('year', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'requests' => $requests,
                'balances' => $balances,
            ],
        ]);
    }

    /**
     * Obtener notificaciones del usuario
     */
    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Por ahora retornamos las notificaciones de Laravel
        $notifications = $user->notifications()->limit(50)->get();
        $unreadCount = $user->unreadNotifications()->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'notifications' => $notifications,
                'unread_count' => $unreadCount,
            ],
        ]);
    }

    /**
     * Marcar notificación como leída
     */
    public function markNotificationRead(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        $notification = $user->notifications()->find($notificationId);

        if (!$notification) {
            return response()->json([
                'status' => 'error',
                'message' => 'Notificación no encontrada',
            ], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'status' => 'success',
            'message' => 'Notificación marcada como leída',
        ]);
    }

    /**
     * Marcar todas las notificaciones como leídas
     */
    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->unreadNotifications->markAsRead();

        return response()->json([
            'status' => 'success',
            'message' => 'Todas las notificaciones marcadas como leídas',
        ]);
    }
}
