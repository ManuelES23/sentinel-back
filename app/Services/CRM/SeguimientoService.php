<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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

    public const TIPOS_AGENDA = ['llamada', 'visita', 'reunion', 'tarea', 'correo'];

    public const DURACION_POR_DEFECTO = 30;

    /** Reglas de validación de un siguiente paso anidado bajo $prefijo. */
    public function reglasSiguiente(string $prefijo): array
    {
        return [
            $prefijo => 'nullable|array',
            "{$prefijo}.tipo" => ["required_with:{$prefijo}", Rule::in(self::TIPOS_AGENDA)],
            "{$prefijo}.titulo" => "required_with:{$prefijo}|string|max:255",
            "{$prefijo}.descripcion" => 'nullable|string|max:2000',
            "{$prefijo}.fecha_inicio" => "required_with:{$prefijo}|date|after:now",
            "{$prefijo}.duracion_minutos" => 'nullable|integer|min:5|max:480',
        ];
    }

    public function mensajesSiguiente(string $prefijo): array
    {
        return ["{$prefijo}.fecha_inicio.after" => 'El siguiente paso debe programarse en el futuro.'];
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
