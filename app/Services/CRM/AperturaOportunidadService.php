<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProducto;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Abre una oportunidad nueva para un cliente o prospecto (spec 2026-10-30
 * §5.3): es lo que se hace cuando la actividad que el vendedor quiere
 * capturar no tiene todavía dónde vivir. Lo usa POST crm/oportunidades/rapida.
 *
 * No abre su propia transacción: el que llama la abre para que la oportunidad
 * y lo que se cuelgue de ella se guarden juntos.
 */
class AperturaOportunidadService
{
    /** `{vendedor}` es el nombre del vendedor asignado; para prospectos se dice «prospecto». */
    public const MENSAJE_AJENA = 'Este cliente es de {vendedor}; pide que te lo reasignen.';

    public function __construct(private readonly VisibilidadOportunidades $visibilidad) {}

    /**
     * @param  array{tipo_producto?: ?string, monto_esperado?: int|float|string|null}  $datos
     * @param  CrmCliente|CrmProspecto  $entidad
     *
     * @throws ValidationException prospecto ya convertido o vendedor que no se puede usar.
     */
    public function abrir(int $empresaId, Model $entidad, array $datos, ?int $vendedorSolicitado): CrmOportunidad
    {
        abort_unless((int) $entidad->empresa_id === $empresaId, 404, 'Registro no encontrado.');

        if ($entidad instanceof CrmProspecto) {
            $this->exigirNoConvertido($empresaId, $entidad);
        }

        $vendedorId = $this->resolverVendedor($empresaId, $entidad, $vendedorSolicitado);
        $tipoProducto = $datos['tipo_producto'] ?? null;
        $nombre = filled($tipoProducto) ? "{$entidad->nombre} · {$tipoProducto}" : $entidad->nombre;

        return CrmOportunidad::create([
            'empresa_id' => $empresaId,
            $entidad instanceof CrmProspecto ? 'prospecto_id' : 'cliente_id' => $entidad->id,
            'vendedor_id' => $vendedorId,
            'nombre' => mb_substr($nombre, 0, 255),
            'etapa' => 'prospecto',
            'tipo_producto' => filled($tipoProducto) ? $tipoProducto : null,
            'monto_esperado' => $datos['monto_esperado'] ?? 0,
        ]);
    }

    /**
     * Reglas de `tipo_producto`. Si la empresa tiene commodities y no captura
     * productos en la oportunidad, es obligatorio en el alta y debe ser uno de
     * ellos; en la edición solo se valida si cambia (un valor viejo ya guardado
     * no se revalida). Sin commodities, o con productos en la oportunidad, es
     * texto libre opcional.
     *
     * @return array<int, mixed>
     */
    public function reglasTipoProducto(int $empresaId, bool $conProductos, ?CrmOportunidad $actual = null): array
    {
        $reglas = $actual ? ['sometimes', 'nullable'] : ['nullable'];
        $reglas[] = 'string';
        $reglas[] = 'max:100';

        $commodities = CrmProducto::commoditiesDe($empresaId);
        if ($commodities->isEmpty() || $conProductos) {
            return $reglas;
        }

        if ($actual === null) {
            $reglas[0] = 'required';
        }
        $reglas[] = function (string $atributo, mixed $valor, Closure $fail) use ($commodities, $actual) {
            if ($valor !== $actual?->tipo_producto && ! $commodities->contains($valor)) {
                $fail('El tipo de producto debe ser uno de los productos de la empresa.');
            }
        };

        return $reglas;
    }

    private function exigirNoConvertido(int $empresaId, CrmProspecto $prospecto): void
    {
        $cliente = CrmCliente::where('empresa_id', $empresaId)->where('prospecto_id', $prospecto->id)->first();
        if ($cliente) {
            throw ValidationException::withMessages([
                'prospecto_id' => "Este prospecto ya es cliente ({$cliente->nombre}); registra la actividad con el cliente.",
            ]);
        }
    }

    /**
     * Dueño de la oportunidad. Sin `oportunidades.equipo` solo puede ser el
     * vendedor activo de quien captura, y un cliente o prospecto de otro
     * vendedor se bloquea. Con `equipo`: el pedido, si no el asignado a la
     * entidad y, si no, quien captura.
     */
    private function resolverVendedor(int $empresaId, Model $entidad, ?int $solicitado): int
    {
        $propio = $this->visibilidad->vendedorPropioId($empresaId);
        $asignado = $entidad->vendedor_id !== null ? (int) $entidad->vendedor_id : null;

        if (! $this->visibilidad->veAlEquipo($empresaId)) {
            abort_unless($propio !== null, 403, VisibilidadOportunidades::MENSAJE_SIN_VENDEDOR);
            abort_unless($solicitado === null || $solicitado === $propio, 403, 'No puedes asignar la oportunidad a otro vendedor.');
            if ($asignado !== null && $asignado !== $propio) {
                abort(403, $this->mensajeAjena($entidad, $asignado));
            }

            return $propio;
        }

        $vendedorId = $solicitado ?? $asignado ?? $propio;
        if ($vendedorId === null) {
            throw ValidationException::withMessages([
                'vendedor_id' => 'Elige el vendedor al que se asignará la oportunidad.',
            ]);
        }

        $activo = CrmVendedor::where('empresa_id', $empresaId)->activo()->whereKey($vendedorId)->exists();
        if (! $activo) {
            throw ValidationException::withMessages([
                'vendedor_id' => 'El vendedor no existe, está inactivo o no es de esta empresa.',
            ]);
        }

        return $vendedorId;
    }

    private function mensajeAjena(Model $entidad, int $vendedorId): string
    {
        $nombre = CrmVendedor::whereKey($vendedorId)->value('nombre') ?? 'otro vendedor';
        $mensaje = str_replace('{vendedor}', $nombre, self::MENSAJE_AJENA);

        return $entidad instanceof CrmProspecto ? str_replace('Este cliente', 'Este prospecto', $mensaje) : $mensaje;
    }
}
