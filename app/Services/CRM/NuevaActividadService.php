<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Nueva actividad» de Mi día (spec 2026-10-30 §5.3): en una sola transacción
 * crea el prospecto (si es nuevo), abre la oportunidad (si hace falta) y
 * registra un evento futuro o una actividad ya hecha, todo a nombre del
 * vendedor que se está viendo.
 *
 * No emite eventos en tiempo real: lo hace el controlador con lo devuelto, ya
 * confirmada la transacción. Los datos llegan validados por el controlador.
 */
class NuevaActividadService
{
    public function __construct(
        private readonly AperturaOportunidadService $apertura,
        private readonly SeguimientoService $seguimientos,
        private readonly VendedorActualService $vendedores,
        private readonly VisibilidadOportunidades $visibilidad,
    ) {}

    /**
     * @param  array<string, mixed>  $datos  `prospecto_nuevo`, `cliente_id`, `prospecto_id` u `oportunidad_id` (uno solo),
     *                                       `tipo_producto`, `monto_esperado`, `vendedor_id`, y `evento` o `actividad` (uno solo).
     * @return array{oportunidad: CrmOportunidad, evento: ?CrmAgenda, actividad: ?CrmActividad, prospecto: ?CrmProspecto, oportunidad_nueva: bool}
     */
    public function crear(int $empresaId, User $usuario, array $datos): array
    {
        $vendedor = $this->vendedores->resolver(
            $empresaId,
            $usuario,
            isset($datos['vendedor_id']) ? (int) $datos['vendedor_id'] : null,
        );

        return DB::transaction(function () use ($empresaId, $datos, $vendedor) {
            $prospecto = null;
            $nueva = empty($datos['oportunidad_id']);

            if ($nueva) {
                if (! empty($datos['prospecto_nuevo'])) {
                    $prospecto = $this->crearProspecto($empresaId, $datos['prospecto_nuevo'], $vendedor->id);
                    $entidad = $prospecto;
                } else {
                    $entidad = $this->buscarEntidad($empresaId, $datos);
                }
                $oportunidad = $this->apertura->abrir($empresaId, $entidad, $datos, $vendedor->id);
            } else {
                $oportunidad = $this->oportunidadAbierta($empresaId, (int) $datos['oportunidad_id']);
            }

            $evento = ! empty($datos['evento'])
                ? $this->seguimientos->programar($empresaId, $vendedor->id, $oportunidad, $datos['evento'])
                : null;
            $actividad = ! empty($datos['actividad'])
                ? $this->seguimientos->crearActividad($empresaId, $vendedor->id, $oportunidad, $datos['actividad'])
                : null;

            return [
                'oportunidad' => $oportunidad,
                'evento' => $evento,
                'actividad' => $actividad,
                'prospecto' => $prospecto,
                'oportunidad_nueva' => $nueva,
            ];
        });
    }

    /** Alta como Prospecto rápido: estatus `nuevo`, a nombre del vendedor. */
    private function crearProspecto(int $empresaId, array $datos, int $vendedorId): CrmProspecto
    {
        return CrmProspecto::create([
            'empresa_id' => $empresaId,
            'nombre' => $datos['nombre'],
            'telefono' => $datos['telefono'] ?? null,
            'email' => $datos['email'] ?? null,
            'estatus' => 'nuevo',
            'vendedor_id' => $vendedorId,
        ]);
    }

    private function buscarEntidad(int $empresaId, array $datos): Model
    {
        $entidad = isset($datos['cliente_id'])
            ? CrmCliente::where('empresa_id', $empresaId)->find($datos['cliente_id'])
            : CrmProspecto::where('empresa_id', $empresaId)->find($datos['prospecto_id']);
        // La validación ya excluye lo inexistente o eliminado; esto cubre una carrera.
        abort_unless($entidad, 404, 'El cliente o prospecto no existe o fue eliminado.');

        return $entidad;
    }

    /**
     * Oportunidad existente de la empresa y visible para el usuario (si no, el
     * mismo 404 que una inexistente), bloqueada para que no se pierda a media
     * transacción. Las ganadas aceptan actividad; las perdidas no.
     *
     * @throws ValidationException si está perdida.
     */
    private function oportunidadAbierta(int $empresaId, int $oportunidadId): CrmOportunidad
    {
        $oportunidad = $this->visibilidad->entidadVisible(
            CrmOportunidad::where('empresa_id', $empresaId)->lockForUpdate()->find($oportunidadId)
        );
        abort_unless($oportunidad, 404, 'Oportunidad no encontrada');

        if ($oportunidad->etapa === 'cerrado_perdido') {
            throw ValidationException::withMessages([
                'oportunidad_id' => 'Esta oportunidad está perdida; abre una nueva para registrar la actividad.',
            ]);
        }

        return $oportunidad;
    }
}
