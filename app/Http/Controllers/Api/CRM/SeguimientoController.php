<?php

namespace App\Http\Controllers\Api\CRM;

use App\Events\CRM\ActividadUpdated;
use App\Events\CRM\AgendaUpdated;
use App\Services\CRM\ContactoActividadService;
use App\Services\CRM\SeguimientoService;
use App\Services\CRM\VendedorActualService;
use App\Services\CRM\VisibilidadOportunidades;
use App\Traits\CRM\FiltraPorEmpresa;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        private readonly VisibilidadOportunidades $visibilidad,
        private readonly ContactoActividadService $contactos,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $empresaId = $this->getEmpresaId();
        abort_unless($empresaId, 403, 'No se pudo determinar el contexto de empresa.');

        // Sobre una oportunidad la etiqueta es obligatoria, salvo en lo registrado desde el
        // botón de WhatsApp. En lo demás es opcional (pero válida si viene).
        $esOportunidad = $request->input('entidad_tipo') === 'oportunidad';
        $exigeEtiquetaActividad = $esOportunidad
            && $request->input('actividad.fuente') !== SeguimientoService::FUENTE_WHATSAPP_ENLACE;

        $validated = $request->validate(array_merge([
            'entidad_tipo' => ['required', Rule::in(array_keys(SeguimientoService::TIPOS_ENTIDAD))],
            'entidad_id' => 'required|integer',
            'vendedor_id' => 'nullable|integer',
            'actividad' => 'nullable|array',
            'actividad.tipo' => [
                'required_with:actividad',
                Rule::in(SeguimientoService::TIPOS_ACTIVIDAD),
                // Lo registrado desde el botón de WhatsApp solo puede ser un whatsapp.
                function (string $atributo, mixed $valor, Closure $fallar) use ($request) {
                    if ($request->input('actividad.fuente') === SeguimientoService::FUENTE_WHATSAPP_ENLACE && $valor !== 'whatsapp') {
                        $fallar('Una actividad registrada desde el botón de WhatsApp debe ser de tipo whatsapp.');
                    }
                },
            ],
            'actividad.descripcion' => 'required_with:actividad|string|max:2000',
            'actividad.resultado' => 'nullable|string|max:2000',
            // Con whatsapp_enlace la fecha la pone el servidor (abajo): no se valida.
            // Opcional: si no viene, `crearActividad` usa la hora del servidor (el front
            // solo la manda cuando el usuario la edita, para no arrastrar el huso del
            // dispositivo). Si viene, debe ser una fecha que no sea futura.
            'actividad.fecha_actividad' => [
                'exclude_if:actividad.fuente,'.SeguimientoService::FUENTE_WHATSAPP_ENLACE,
                'nullable',
                'date',
                'before_or_equal:'.now()->addMinutes(5)->toDateTimeString(),
            ],
            'actividad.fuente' => ['nullable', Rule::in(SeguimientoService::FUENTES_REGISTRO)],
            'actividad.etiqueta_id' => [
                $exigeEtiquetaActividad ? 'required_with:actividad' : 'nullable',
                ...$this->seguimientos->reglaEtiqueta($empresaId),
            ],
        ], $this->seguimientos->reglasSiguiente('siguiente', $empresaId, $esOportunidad), $this->contactos->reglas()), array_merge(
            $this->seguimientos->mensajesSiguiente('siguiente'),
            $this->seguimientos->mensajesEtiqueta('actividad.etiqueta_id'),
            ['actividad.fuente.in' => 'La fuente de la actividad no es válida.'],
        ));

        $actividad = $validated['actividad'] ?? null;

        // WhatsApp (Fase 0): se registra cuando el vendedor confirma el envío, así
        // que la hora es la del servidor. La del dispositivo puede venir de otro
        // huso (CDMX va una hora adelante de Mazatlán) y se ignora siempre. Se
        // sobrescribe a mano porque `$validated['actividad']` puede traer la
        // fecha cruda aunque la regla la excluya.
        if (! empty($actividad) && ($actividad['fuente'] ?? null) === SeguimientoService::FUENTE_WHATSAPP_ENLACE) {
            $actividad['fecha_actividad'] = now();
        }
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

        // Todo seguimiento nuevo vive dentro de una oportunidad (cliente y prospecto directo, 422).
        $this->seguimientos->exigirCapturaConOportunidad($validated['entidad_tipo']);

        // Una oportunidad que el usuario no puede ver responde igual que una inexistente.
        $entidad = $this->visibilidad->entidadVisible(
            $this->seguimientos->buscarEntidad($empresaId, $validated['entidad_tipo'], (int) $validated['entidad_id'])
        );
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

        // El contacto (elegido o creado en línea) se resuelve en la misma transacción que la
        // actividad y el siguiente paso, y los dos llevan el mismo. El permiso es el de la
        // actividad, no el de editar prospectos/clientes.
        $contactoCreado = null;
        $resultado = DB::transaction(function () use ($empresaId, $vendedor, $entidad, $actividad, $siguiente, $validated, &$contactoCreado) {
            $contactoId = $this->contactos->resolver(
                $empresaId,
                $entidad,
                $validated,
                $this->contactos->exige($empresaId, $entidad, $actividad['tipo'] ?? null, $actividad['fuente'] ?? 'manual'),
                $contactoCreado,
            );

            return $this->seguimientos->registrar(
                $empresaId,
                $vendedor->id,
                $entidad,
                empty($actividad) ? null : $actividad + ['contacto_id' => $contactoId],
                empty($siguiente) ? null : $siguiente + ['contacto_id' => $contactoId],
            );
        });

        $this->difundirContactoCreado($contactoCreado);

        if ($resultado['actividad']) {
            $this->difundir(new ActividadUpdated('created', $resultado['actividad']->load(ActividadController::RELACIONES)->toArray()));
        }
        if ($resultado['evento']) {
            $this->difundir(new AgendaUpdated('created', $resultado['evento']->load(['vendedor:id,nombre', 'etiqueta:id,nombre', 'contacto:id,nombre,cargo'])->toArray()));
        }

        return $this->jsonSuccess($resultado, 'Seguimiento registrado correctamente', 201);
    }
}
