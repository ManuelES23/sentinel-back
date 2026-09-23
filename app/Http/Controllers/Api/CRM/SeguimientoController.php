<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Events\CRM\AgendaUpdated;
use App\Services\CRM\SeguimientoService;
use App\Services\CRM\VendedorActualService;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** POST /crm/seguimientos — registrar lo que pasó + siguiente paso (CRM fase 2). */
class SeguimientoController extends CrmBaseController
{
    use FiltraPorEmpresa;
    use VerificaPermisoSubmodulo;

    public function __construct(
        private readonly SeguimientoService $seguimientos,
        private readonly VendedorActualService $vendedores,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        $validated = $request->validate(array_merge([
            'entidad_tipo' => ['required', Rule::in(array_keys(SeguimientoService::TIPOS_ENTIDAD))],
            'entidad_id' => 'required|integer',
            'vendedor_id' => 'nullable|integer',
            'actividad' => 'nullable|array',
            'actividad.tipo' => ['required_with:actividad', Rule::in(SeguimientoService::TIPOS_ACTIVIDAD)],
            'actividad.descripcion' => 'required_with:actividad|string|max:2000',
            'actividad.resultado' => 'nullable|string|max:2000',
            'actividad.fecha_actividad' => 'required_with:actividad|date|before_or_equal:'.now()->addMinutes(5)->toDateTimeString(),
        ], $this->seguimientos->reglasSiguiente('siguiente')), $this->seguimientos->mensajesSiguiente('siguiente'));

        $actividad = $validated['actividad'] ?? null;
        $siguiente = $validated['siguiente'] ?? null;

        if (empty($actividad) && empty($siguiente)) {
            throw ValidationException::withMessages([
                'actividad' => 'Registra lo que pasó o programa un siguiente paso.',
            ]);
        }

        if (! empty($actividad)) {
            $this->exigirPermisoSubmodulo($empresaId, 'actividades', 'actividades', 'crear', 'No tienes permiso para registrar actividades.');
        }
        if (! empty($siguiente)) {
            $this->exigirPermisoSubmodulo($empresaId, 'agenda', 'agenda', 'crear', 'No tienes permiso para programar eventos de agenda.');
        }

        $entidad = $this->seguimientos->buscarEntidad($empresaId, $validated['entidad_tipo'], (int) $validated['entidad_id']);
        if (! $entidad) {
            throw ValidationException::withMessages([
                'entidad_id' => 'El registro no existe o no pertenece a la empresa.',
            ]);
        }

        $vendedor = $this->vendedores->resolver(
            $empresaId,
            $request->user(),
            isset($validated['vendedor_id']) ? (int) $validated['vendedor_id'] : null,
        );

        $resultado = $this->seguimientos->registrar(
            $empresaId,
            $vendedor->id,
            $entidad,
            empty($actividad) ? null : $actividad,
            empty($siguiente) ? null : $siguiente,
        );

        if ($resultado['actividad']) {
            $this->difundir(new ActividadUpdated('created', $resultado['actividad']->toArray(), null, 'crm', 'actividades'));
        }
        if ($resultado['evento']) {
            $this->difundir(new AgendaUpdated('created', $resultado['evento']->load('vendedor:id,nombre')->toArray(), null, 'crm', 'agenda'));
        }

        return $this->jsonSuccess($resultado, 'Seguimiento registrado correctamente', 201);
    }
}
