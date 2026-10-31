<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmEtiquetaActividad;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Registrar lo que pasó y programar el siguiente paso en una sola acción
 * (CRM fase 2). Lo usan SeguimientoController, AgendaController::completar
 * y ProspectoController::storeRapido.
 */
class SeguimientoService
{
    public const TIPOS_ENTIDAD = [
        'prospecto' => CrmProspecto::class,
        'cliente' => CrmCliente::class,
        'oportunidad' => CrmOportunidad::class,
        'empresa_externa' => CrmEmpresaExterna::class,
    ];

    public const TIPOS_ACTIVIDAD = ['llamada', 'whatsapp', 'correo', 'visita', 'reunion', 'nota'];

    public const TIPOS_AGENDA = ['llamada', 'visita', 'reunion', 'tarea', 'correo', 'whatsapp'];

    /**
     * El vendedor declara que envió el mensaje desde el botón de WhatsApp
     * (Fase 0). La futura captura por API usará otra (`whatsapp_api`).
     */
    public const FUENTE_WHATSAPP_ENLACE = 'whatsapp_enlace';

    /** Fuentes que acepta POST crm/seguimientos. */
    public const FUENTES_REGISTRO = ['manual', self::FUENTE_WHATSAPP_ENLACE];

    public const DURACION_POR_DEFECTO = 30;

    public const MENSAJE_ETIQUETA = 'Elige una etiqueta válida.';

    /**
     * Entidades cuya captura sigue sin oportunidad (spec 2026-10-30 §7): una
     * empresa externa no puede tener oportunidades. Los eventos sin entidad
     * (juntas internas, Outlook) tampoco la necesitan.
     */
    public const ENTIDADES_SIN_OPORTUNIDAD = ['empresa_externa'];

    /**
     * Reglas de validación de un siguiente paso anidado bajo $prefijo. La
     * etiqueta es obligatoria cuando el siguiente paso cuelga de una
     * oportunidad ($exigeEtiqueta) y opcional (pero validada) en lo demás.
     */
    public function reglasSiguiente(string $prefijo, int $empresaId, bool $exigeEtiqueta = false): array
    {
        return [
            $prefijo => 'nullable|array',
            "{$prefijo}.tipo" => ["required_with:{$prefijo}", Rule::in(self::TIPOS_AGENDA)],
            "{$prefijo}.titulo" => "required_with:{$prefijo}|string|max:255",
            "{$prefijo}.descripcion" => 'nullable|string|max:2000',
            "{$prefijo}.fecha_inicio" => "required_with:{$prefijo}|date|after:now",
            "{$prefijo}.duracion_minutos" => 'nullable|integer|min:5|max:480',
            "{$prefijo}.etiqueta_id" => [
                $exigeEtiqueta ? "required_with:{$prefijo}" : 'nullable',
                ...$this->reglaEtiqueta($empresaId),
            ],
        ];
    }

    public function mensajesSiguiente(string $prefijo): array
    {
        return [
            "{$prefijo}.fecha_inicio.after" => 'El siguiente paso debe programarse en el futuro.',
        ] + $this->mensajesEtiqueta("{$prefijo}.etiqueta_id");
    }

    /**
     * Reglas de un `etiqueta_id`: entero de una etiqueta ACTIVA de la empresa.
     * El que llama decide si es `required` o `nullable`.
     *
     * @return array<int, mixed>
     */
    public function reglaEtiqueta(int $empresaId): array
    {
        return [
            'integer',
            Rule::exists((new CrmEtiquetaActividad)->getTable(), 'id')
                ->where(fn ($q) => $q->where('empresa_id', $empresaId)->where('activo', true)),
        ];
    }

    /** Mensajes de reglaEtiqueta() (y de su `required`) para el campo `$campo`. */
    public function mensajesEtiqueta(string $campo): array
    {
        return [
            "{$campo}.required" => self::MENSAJE_ETIQUETA,
            "{$campo}.required_with" => self::MENSAJE_ETIQUETA,
            "{$campo}.integer" => self::MENSAJE_ETIQUETA,
            "{$campo}.exists" => self::MENSAJE_ETIQUETA,
        ];
    }

    /**
     * Toda captura nueva vive dentro de una oportunidad: ligarla directo a un
     * cliente o prospecto responde 422 en `$campo`. Pasan la oportunidad, la
     * empresa externa y la ausencia de entidad.
     *
     * @throws ValidationException
     */
    public function exigirCapturaConOportunidad(?string $entidadTipo, string $campo = 'entidad_tipo'): void
    {
        if (! in_array($entidadTipo, ['cliente', 'prospecto'], true)) {
            return;
        }

        throw ValidationException::withMessages([
            $campo => "Elige una oportunidad o abre una nueva para este {$entidadTipo}.",
        ]);
    }

    public function buscarEntidad(int $empresaId, string $tipo, int $id): ?Model
    {
        $clase = self::TIPOS_ENTIDAD[$tipo] ?? null;

        return $clase ? $clase::where('empresa_id', $empresaId)->find($id) : null;
    }

    public function crearActividad(int $empresaId, int $vendedorId, Model $entidad, array $datos): CrmActividad
    {
        return CrmActividad::create([
            'empresa_id' => $empresaId,
            'entidad_type' => $entidad::class,
            'entidad_id' => $entidad->id,
            'vendedor_id' => $vendedorId,
            'tipo' => $datos['tipo'],
            'descripcion' => $datos['descripcion'],
            'resultado' => $datos['resultado'] ?? null,
            'fecha_actividad' => $datos['fecha_actividad'] ?? now(),
            'fuente' => $datos['fuente'] ?? 'manual',
            'etiqueta_id' => $datos['etiqueta_id'] ?? null,
        ]);
    }

    public function programar(int $empresaId, int $vendedorId, ?Model $entidad, array $datos): CrmAgenda
    {
        $inicio = CarbonImmutable::parse($datos['fecha_inicio']);
        $duracion = (int) ($datos['duracion_minutos'] ?? self::DURACION_POR_DEFECTO);

        return CrmAgenda::create([
            'empresa_id' => $empresaId,
            'vendedor_id' => $vendedorId,
            'entidad_type' => $entidad ? $entidad::class : null,
            'entidad_id' => $entidad?->id,
            'tipo' => $datos['tipo'],
            'titulo' => $datos['titulo'],
            'descripcion' => $datos['descripcion'] ?? null,
            'fecha_inicio' => $inicio,
            'fecha_fin' => $inicio->addMinutes($duracion),
            'completado' => false,
            'etiqueta_id' => $datos['etiqueta_id'] ?? null,
        ]);
    }

    /** @return array{actividad: ?CrmActividad, evento: ?CrmAgenda} */
    public function registrar(int $empresaId, int $vendedorId, Model $entidad, ?array $actividad, ?array $siguiente): array
    {
        return DB::transaction(fn () => [
            'actividad' => $actividad ? $this->crearActividad($empresaId, $vendedorId, $entidad, $actividad) : null,
            'evento' => $siguiente ? $this->programar($empresaId, $vendedorId, $entidad, $siguiente) : null,
        ]);
    }
}
