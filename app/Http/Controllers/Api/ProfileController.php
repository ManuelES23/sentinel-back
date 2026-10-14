<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exceptions\RH\VacacionesException;
use App\Models\VacationBalance;
use App\Models\VacationRequest;
use App\Services\RH\VacationRequestService;
use App\Services\VacationCalculatorService;
use App\Support\PasswordPolicy;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

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
            
            // Saldo con la misma fuente que RH y el portal.
            $info = VacationCalculatorService::getEmployeeVacationInfo($employee);
            $acumulado = $info['accumulated'] ?? [];
            $yearsOfService = (int) ($info['years_of_service'] ?? 0);
            
            // Solicitudes del año actual y posteriores (una de diciembre puede ser de enero)
            $vacationRequests = VacationRequest::where('employee_id', $employee->id)
                ->where('vacation_year', '>=', $currentYear)
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
                ? (int) ($employee->hire_date->diffInMonths(now()) % 12)
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
            
            $vacationData = [
                'balance' => [
                    'accumulated_days' => ($acumulado['total_accumulated_days'] ?? 0) + ($acumulado['total_adjustment_days'] ?? 0),
                    'raw_accumulated_days' => $acumulado['total_accumulated_days'] ?? 0,
                    'entitled_days' => $info['entitled_days'] ?? 0,
                    'available_days' => VacationCalculatorService::diasDisponibles($employee),
                    'used_days' => $acumulado['total_used_days'] ?? 0,
                    'pending_days' => $acumulado['total_pending_days'] ?? 0,
                    'carried_over' => 0,
                    'adjustment_days' => $acumulado['total_adjustment_days'] ?? 0,
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
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ], PasswordPolicy::messages());

        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'La contraseña actual es incorrecta',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Contraseña actualizada correctamente',
        ]);
    }

    /**
     * Solicitar vacaciones desde el perfil (usuario con empleado ligado).
     */
    public function requestVacation(Request $request, VacationRequestService $vacaciones): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        if (! $employee) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes un empleado vinculado para solicitar vacaciones',
            ], 400);
        }

        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date',
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $vacationRequest = $vacaciones->crear(
                $employee,
                Carbon::parse($validated['start_date']),
                Carbon::parse($validated['end_date']),
                $validated['reason'] ?? null,
                VacationRequest::SOURCE_PERFIL,
                $user,
            );
        } catch (VacacionesException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Solicitud de vacaciones enviada correctamente',
            'data' => $vacationRequest,
        ]);
    }

    /**
     * Cancelar una solicitud propia que sigue en revisión.
     */
    public function cancelVacationRequest(Request $request, VacationRequest $vacationRequest, VacationRequestService $vacaciones): JsonResponse
    {
        $employee = $request->user()->employee;

        if (! $employee || $vacationRequest->employee_id !== $employee->id) {
            return response()->json([
                'status' => 'error',
                'message' => 'No tienes permiso para cancelar esta solicitud',
            ], 403);
        }

        try {
            $vacaciones->cancelar($vacationRequest, VacationRequestService::CANCELA_EMPLEADO);
        } catch (VacacionesException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }

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
            ->with(['approver:id,name', 'approvals'])
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
