<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmMotivoRechazo;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadAjuste;
use App\Models\CRM\CrmOportunidadVenta;
use App\Models\CRM\CrmOportunidadVentaRenglon;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rechazos de cajas y ajustes de precio después de la venta, solo SBP (spec
 * 2026-11-25 §3). Cada ajuste guarda su importe con signo; la oportunidad
 * recalcula `monto_esperado` = lo vendido + Σ importes vigentes en la misma
 * transacción, sin tocar etapa, fecha de cierre ni estado de cobro. Todo
 * ocurre con la oportunidad bloqueada y el estado revalidado bajo el candado;
 * los eventos en tiempo real los difunde el controlador DESPUÉS de confirmar.
 *
 * Los importes se calculan en centavos enteros: el descuento global de la
 * venta se aplica igual que al monto vendido y se redondea al centavo hacia
 * arriba (en valor absoluto), de modo que el monto siempre cuadra con la suma.
 */
class AjustesOportunidadService
{
    public const MENSAJE_SIN_VENTA = 'Esta oportunidad no tiene una venta registrada.';

    public const MENSAJE_SOLO_ULTIMO = 'Solo se puede anular el último ajuste.';

    public const MENSAJE_YA_ANULADO = 'Ese ajuste ya está anulado.';

    private const MENSAJE_RENGLON = 'Elige un renglón vendido de la venta.';

    private const MENSAJE_SIN_CAJAS = 'Este renglón ya no tiene cajas vigentes.';

    private const MENSAJE_TIPO = 'Elige si es un rechazo de cajas o un ajuste de precio.';

    private const MENSAJE_CAJAS = 'Las cajas deben ser un número entero mayor a cero.';

    private const MENSAJE_PRECIO = 'El precio nuevo debe ser un número de 0 en adelante, con máximo 2 decimales.';

    private const MENSAJE_PRECIO_IGUAL = 'El precio nuevo es igual al vigente.';

    private const MENSAJE_MOTIVO = 'Elige el motivo.';

    private const MENSAJE_FECHA = 'La fecha no es válida.';

    private const MENSAJE_FECHA_FUTURA = 'La fecha no puede ser futura.';

    private const MENSAJE_FECHA_ANTERIOR = 'La fecha no puede ser anterior a la venta.';

    private const MENSAJE_NOTA = 'La nota no es válida.';

    private const MENSAJE_MONTO = 'El monto vendido excede el máximo permitido.';

    private const MENSAJE_MOTIVO_ANULACION = 'Escribe por qué se anula el ajuste.';

    public function __construct(private readonly VentaOportunidadService $ventas) {}

    /**
     * Lo vendido por renglón con los ajustes vigentes aplicados en orden de id.
     *
     * @return array<int, array{cajas: int, precio: float}> renglón vendido id => cajas y precio vigentes
     */
    public function vigentes(CrmOportunidadVenta $venta): array
    {
        $renglones = $venta->renglones()->where('vendido', true)->orderBy('id')->get();

        $estado = [];
        foreach ($renglones as $renglon) {
            $estado[(int) $renglon->id] = [
                'cajas' => (int) $renglon->cantidad_vendida,
                'precio' => (float) $renglon->precio_vendido,
            ];
        }

        foreach ($this->ajustesVigentes($venta) as $ajuste) {
            $id = (int) $ajuste->venta_renglon_id;
            if (! isset($estado[$id])) {
                continue;
            }
            if ($ajuste->tipo === 'rechazo') {
                $estado[$id]['cajas'] -= (int) $ajuste->cajas;
            } else {
                $estado[$id]['precio'] = (float) $ajuste->precio_nuevo;
            }
        }

        return $estado;
    }

    /**
     * @param  array<string, mixed>  $datos  `{venta_renglon_id, tipo, cajas?, precio_nuevo?, motivo_rechazo_id, fecha?, nota?}` tal cual llegó
     * @return array{oportunidad: CrmOportunidad, ajuste: CrmOportunidadAjuste, actividad: CrmActividad}
     *
     * @throws VentaNoRegistrableException no hay una venta firme sobre la cual ajustar (422 sin campo)
     * @throws ValidationException datos inválidos (422 con la clave del campo)
     */
    public function registrar(CrmOportunidad $op, array $datos, int $userId): array
    {
        return DB::transaction(function () use ($op, $datos, $userId) {
            $oportunidad = CrmOportunidad::whereKey($op->id)->lockForUpdate()->firstOrFail();
            $venta = $this->ventaFirme($oportunidad);

            $renglones = $venta->renglones()->where('vendido', true)->orderBy('id')->get();
            $vigentes = $this->vigentes($venta);
            $ajuste = $this->validar($datos, $venta, $renglones, $vigentes, (int) $oportunidad->empresa_id);

            $actuales = $this->centavosFirmes($venta);
            $nuevo = $actuales + $ajuste['importe'];
            if ($nuevo < 0 || $nuevo > (int) round(CrmOportunidad::MONTO_MAXIMO * 100)) {
                throw ValidationException::withMessages([$ajuste['campo_monto'] => self::MENSAJE_MONTO]);
            }

            $renglon = $renglones->firstWhere('id', $ajuste['renglon_id']);
            $importe = $ajuste['importe'] / 100;

            $registro = CrmOportunidadAjuste::create([
                'empresa_id' => $oportunidad->empresa_id,
                'oportunidad_id' => $oportunidad->id,
                'venta_renglon_id' => $renglon->id,
                'tipo' => $ajuste['tipo'],
                'cajas' => $ajuste['cajas'],
                'precio_nuevo' => $ajuste['precio_nuevo'],
                'importe' => $importe,
                'motivo_rechazo_id' => $ajuste['motivo_id'],
                'nota' => $ajuste['nota'],
                'fecha' => $ajuste['fecha'],
                'user_id' => $userId,
            ]);

            $oportunidad->monto_esperado = $nuevo / 100;
            $oportunidad->save();

            $motivo = $this->etiquetaMotivo($ajuste['motivo_id']);
            $descripcion = $ajuste['tipo'] === 'rechazo'
                ? sprintf(
                    'Rechazo: %s cajas %s (%s) · %s',
                    number_format($ajuste['cajas']), $this->descripcionRenglon($renglon), VentaOportunidadService::dinero($importe), $motivo
                )
                : sprintf(
                    'Ajuste de precio: %s %s → %s (%s) · %s',
                    $this->descripcionRenglon($renglon),
                    VentaOportunidadService::dinero($vigentes[$renglon->id]['precio']),
                    VentaOportunidadService::dinero($ajuste['precio_nuevo']),
                    VentaOportunidadService::dinero($importe),
                    $motivo
                );

            return [
                'oportunidad' => $oportunidad,
                'ajuste' => $registro->load(['motivoRechazo.categoria:id,nombre', 'usuario:id,name']),
                'actividad' => $this->nota($oportunidad, $descripcion),
            ];
        });
    }

    /**
     * Anula el ÚLTIMO ajuste vigente de la oportunidad (anular uno de en medio
     * cambiaría los importes de los siguientes) y recalcula el monto.
     *
     * @return array{oportunidad: CrmOportunidad, ajuste: CrmOportunidadAjuste, actividad: CrmActividad}
     *
     * @throws VentaNoRegistrableException no hay venta firme, ya está anulado o no es el último (422 sin campo)
     * @throws ValidationException motivo faltante o demasiado largo
     */
    public function anular(CrmOportunidad $op, CrmOportunidadAjuste $ajuste, string $motivo, int $userId): array
    {
        return DB::transaction(function () use ($op, $ajuste, $motivo, $userId) {
            $oportunidad = CrmOportunidad::whereKey($op->id)->lockForUpdate()->firstOrFail();
            $venta = $this->ventaFirme($oportunidad);

            $actual = CrmOportunidadAjuste::whereKey($ajuste->id)->lockForUpdate()->firstOrFail();
            if ((int) $actual->oportunidad_id !== (int) $oportunidad->id) {
                throw new VentaNoRegistrableException(self::MENSAJE_SOLO_ULTIMO);
            }
            if ($actual->anulado_at !== null) {
                throw new VentaNoRegistrableException(self::MENSAJE_YA_ANULADO);
            }
            $ultimo = $this->ajustesVigentes($venta)->last();
            if ($ultimo === null || (int) $ultimo->id !== (int) $actual->id) {
                throw new VentaNoRegistrableException(self::MENSAJE_SOLO_ULTIMO);
            }

            $motivo = trim($motivo);
            if ($motivo === '' || mb_strlen($motivo) > 1000) {
                throw ValidationException::withMessages(['motivo' => self::MENSAJE_MOTIVO_ANULACION]);
            }

            $actual->update([
                'anulado_at' => now(),
                'anulado_por' => $userId,
                'motivo_anulacion' => $motivo,
            ]);

            $oportunidad->monto_esperado = $this->centavosFirmes($venta) / 100;
            $oportunidad->save();

            return [
                'oportunidad' => $oportunidad,
                'ajuste' => $actual->load(['motivoRechazo.categoria:id,nombre', 'usuario:id,name']),
                'actividad' => $this->nota($oportunidad, "Ajuste anulado: {$motivo}"),
            ];
        });
    }

    /**
     * La forma de GET crm/oportunidades/{id}/venta (spec §6): la venta vigente
     * con lo vendido y los renglones no vendidos, los ajustes (vigentes y
     * anulados) y el resumen en dinero y cajas.
     *
     * @return array{venta: ?array<string, mixed>, ajustes: list<array<string, mixed>>, resumen: array<string, int|float>}
     */
    public function resumen(CrmOportunidad $op): array
    {
        $venta = CrmOportunidadVenta::where('oportunidad_id', $op->id)
            ->where('vigente', true)
            ->with(['cotizacion:id,folio', 'registradaPor:id,name', 'renglones.motivoRechazo.categoria:id,nombre'])
            ->first();

        if ($venta === null) {
            return [
                'venta' => null,
                'ajustes' => [],
                'resumen' => $this->cifras(0, 0, 0, 0, 0),
            ];
        }

        $vigentes = $this->vigentes($venta);
        $renglones = $venta->renglones->sortBy('id')->values()->map(function (CrmOportunidadVentaRenglon $r) use ($vigentes) {
            $fila = $r->toArray();
            $fila['cajas_vigentes'] = $r->vendido ? ($vigentes[$r->id]['cajas'] ?? null) : null;
            $fila['precio_vigente'] = $r->vendido ? ($vigentes[$r->id]['precio'] ?? null) : null;

            return $fila;
        })->all();

        $todos = CrmOportunidadAjuste::where('oportunidad_id', $venta->oportunidad_id)
            ->whereIn('venta_renglon_id', $venta->renglones->pluck('id'))
            ->with(['motivoRechazo.categoria:id,nombre', 'usuario:id,name', 'anuladoPor:id,name'])
            ->orderBy('id')
            ->get();
        $ultimoVigenteId = $todos->whereNull('anulado_at')->last()?->id;
        $descripciones = $venta->renglones->mapWithKeys(fn ($r) => [$r->id => $this->descripcionRenglon($r)]);

        $ajustes = $todos->map(function (CrmOportunidadAjuste $a) use ($ultimoVigenteId, $descripciones) {
            $fila = $a->toArray();
            $fila['descripcion_renglon'] = $descripciones[$a->venta_renglon_id] ?? null;
            $fila['vigente'] = $a->anulado_at === null;
            $fila['anulable'] = $a->anulado_at === null && (int) $a->id === (int) $ultimoVigenteId;

            return $fila;
        })->all();

        $vendidoCentavos = $this->centavosVendidos($venta);
        $vigentesAjustes = $todos->whereNull('anulado_at');
        $rechazadoCentavos = $vigentesAjustes->where('tipo', 'rechazo')->sum(fn ($a) => $this->centavos($a->importe));
        $precioCentavos = $vigentesAjustes->where('tipo', 'precio')->sum(fn ($a) => $this->centavos($a->importe));
        $cajasVendidas = (int) $venta->renglones->where('vendido', true)->sum('cantidad_vendida');
        $cajasRechazadas = (int) $vigentesAjustes->where('tipo', 'rechazo')->sum('cajas');

        $payload = $venta->toArray();
        unset($payload['renglones']);
        $payload['renglones'] = $renglones;

        return [
            'venta' => $payload,
            'ajustes' => $ajustes,
            'resumen' => $this->cifras($vendidoCentavos, $rechazadoCentavos, $precioCentavos, $cajasVendidas, $cajasRechazadas),
        ];
    }

    /**
     * Resumen en dinero y cajas. `rechazado` y `ajustes_precio` son la suma de
     * importes cambiada de signo (spec §8): un precio que sube da un número
     * negativo en `ajustes_precio`.
     *
     * @return array<string, int|float>
     */
    private function cifras(int $vendido, int $rechazado, int $precio, int $cajasVendidas, int $cajasRechazadas): array
    {
        return [
            'vendido' => $vendido / 100,
            'rechazado' => -$rechazado / 100,
            'ajustes_precio' => -$precio / 100,
            'firme' => ($vendido + $rechazado + $precio) / 100,
            'cajas_vendidas' => $cajasVendidas,
            'cajas_rechazadas' => $cajasRechazadas,
            'cajas_firmes' => $cajasVendidas - $cajasRechazadas,
        ];
    }

    /**
     * La venta vigente bloqueada, solo si la oportunidad está ganada y al menos
     * un renglón se vendió: una perdida (o una ganada a mano sin venta) no
     * tiene venta firme sobre la cual ajustar.
     */
    private function ventaFirme(CrmOportunidad $oportunidad): CrmOportunidadVenta
    {
        if ($oportunidad->etapa !== 'cerrado_ganado') {
            throw new VentaNoRegistrableException(self::MENSAJE_SIN_VENTA);
        }

        $venta = CrmOportunidadVenta::where('oportunidad_id', $oportunidad->id)
            ->where('vigente', true)
            ->lockForUpdate()
            ->first();

        if ($venta === null || ! $venta->renglones()->where('vendido', true)->exists()) {
            throw new VentaNoRegistrableException(self::MENSAJE_SIN_VENTA);
        }

        return $venta;
    }

    /** Ajustes sin anular de los renglones de la venta, en orden de id. */
    private function ajustesVigentes(CrmOportunidadVenta $venta): Collection
    {
        return CrmOportunidadAjuste::vigentes()
            ->where('oportunidad_id', $venta->oportunidad_id)
            ->whereIn('venta_renglon_id', $venta->renglones()->select('id'))
            ->orderBy('id')
            ->get();
    }

    /** Lo vendido, con el descuento de la venta, en centavos. */
    private function centavosVendidos(CrmOportunidadVenta $venta): int
    {
        return (int) round($this->ventas->montoVenta($venta) * 100);
    }

    /** Lo vendido más los importes vigentes (el `monto_esperado` que corresponde), en centavos. */
    private function centavosFirmes(CrmOportunidadVenta $venta): int
    {
        return $this->centavosVendidos($venta)
            + $this->ajustesVigentes($venta)->sum(fn (CrmOportunidadAjuste $a) => $this->centavos($a->importe));
    }

    private function centavos(mixed $monto): int
    {
        return (int) round((float) $monto * 100);
    }

    /**
     * Revisa lo recibido contra el estado vigente del renglón y calcula el
     * importe con signo (en centavos). Junta todos los errores de campo.
     *
     * @param  array<string, mixed>  $datos
     * @param  Collection<int, CrmOportunidadVentaRenglon>  $renglones  los vendidos de la venta
     * @param  array<int, array{cajas: int, precio: float}>  $vigentes
     * @return array{renglon_id: int, tipo: string, cajas: ?int, precio_nuevo: ?float, importe: int, motivo_id: int, nota: ?string, fecha: string, campo_monto: string}
     */
    private function validar(array $datos, CrmOportunidadVenta $venta, Collection $renglones, array $vigentes, int $empresaId): array
    {
        $errores = [];

        $renglonId = $this->ventas->entero($datos['venta_renglon_id'] ?? null);
        if ($renglonId === null || ! $renglones->contains('id', $renglonId)) {
            $errores['venta_renglon_id'] = self::MENSAJE_RENGLON;
            $renglonId = null;
        }

        $tipo = $datos['tipo'] ?? null;
        if (! in_array($tipo, CrmOportunidadAjuste::TIPOS, true)) {
            $errores['tipo'] = self::MENSAJE_TIPO;
            $tipo = null;
        }

        $motivoId = $this->ventas->entero($datos['motivo_rechazo_id'] ?? null);
        $validos = CrmMotivoRechazo::activos()
            ->whereKey($motivoId ?? 0)
            ->whereIn('categoria_id', CrmCategoriaRechazo::where('empresa_id', $empresaId)->where('activo', true)->select('id'))
            ->exists();
        if (! $validos) {
            $errores['motivo_rechazo_id'] = self::MENSAJE_MOTIVO;
        }

        $nota = $datos['nota'] ?? null;
        if ($nota !== null && (! is_string($nota) || mb_strlen(trim($nota)) > 1000)) {
            $errores['nota'] = self::MENSAJE_NOTA;
            $nota = null;
        }
        $nota = $nota === null || trim($nota) === '' ? null : trim($nota);

        $fecha = $this->fecha($datos['fecha'] ?? null, $venta, $errores);

        $cajas = null;
        $precioNuevo = null;
        $importe = 0;
        $campoMonto = $tipo === 'precio' ? 'precio_nuevo' : 'cajas';

        if ($renglonId !== null && $tipo !== null) {
            $vigente = $vigentes[$renglonId];
            $factor = 10000 - (int) round((float) $venta->descuento_global_pct * 100);
            $maximo = (int) round(CrmOportunidad::MONTO_MAXIMO * 100);

            if ($vigente['cajas'] < 1) {
                $errores['venta_renglon_id'] = self::MENSAJE_SIN_CAJAS;
            } elseif ($tipo === 'rechazo') {
                $cajas = $this->ventas->entero($datos['cajas'] ?? null);
                if ($cajas === null || $cajas < 1) {
                    $errores['cajas'] = self::MENSAJE_CAJAS;
                    $cajas = null;
                } elseif ($cajas > $vigente['cajas']) {
                    $errores['cajas'] = 'No puedes rechazar más de '.number_format($vigente['cajas']).' cajas.';
                    $cajas = null;
                } else {
                    $importe = -$this->aplicarFactor($cajas * $this->centavos($vigente['precio']), $factor);
                }
            } else {
                $precioNuevo = $this->ventas->precio($datos['precio_nuevo'] ?? null);
                if ($precioNuevo === null) {
                    $errores['precio_nuevo'] = self::MENSAJE_PRECIO;
                } elseif ($this->centavos($precioNuevo) === $this->centavos($vigente['precio'])) {
                    $errores['precio_nuevo'] = self::MENSAJE_PRECIO_IGUAL;
                } else {
                    $diferencia = $this->centavos($precioNuevo) - $this->centavos($vigente['precio']);
                    $base = abs($diferencia) * $vigente['cajas'];
                    if ($base > $maximo) {
                        $errores['precio_nuevo'] = self::MENSAJE_MONTO;
                    } else {
                        $importe = ($diferencia < 0 ? -1 : 1) * $this->aplicarFactor($base, $factor);
                    }
                }
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return [
            'renglon_id' => $renglonId,
            'tipo' => $tipo,
            'cajas' => $cajas,
            'precio_nuevo' => $precioNuevo,
            'importe' => $importe,
            'motivo_id' => $motivoId,
            'nota' => $nota,
            'fecha' => $fecha,
            'campo_monto' => $campoMonto,
        ];
    }

    /** `centavos × factor / 10000` redondeado al centavo hacia arriba (centavos ≥ 0). */
    private function aplicarFactor(int $centavos, int $factor): int
    {
        return intdiv($centavos * $factor + 5000, 10000);
    }

    /**
     * Fecha del ajuste como Y-m-d: por omisión hoy; no futura ni anterior al día
     * de la venta. Agrega el error a `$errores` si no vale.
     *
     * @param  array<string, string>  $errores
     */
    private function fecha(mixed $recibida, CrmOportunidadVenta $venta, array &$errores): string
    {
        $hoy = now()->toDateString();
        if ($recibida === null || $recibida === '') {
            return $hoy;
        }

        $dia = is_string($recibida) && preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ].*)?$/', trim($recibida), $m) ? $m[1] : null;
        $valida = $dia !== null && Carbon::createFromFormat('!Y-m-d', $dia) !== false
            && Carbon::createFromFormat('!Y-m-d', $dia)->format('Y-m-d') === $dia;
        if (! $valida) {
            $errores['fecha'] = self::MENSAJE_FECHA;

            return $hoy;
        }
        if ($dia > $hoy) {
            $errores['fecha'] = self::MENSAJE_FECHA_FUTURA;
        } elseif ($dia < $venta->created_at->toDateString()) {
            $errores['fecha'] = self::MENSAJE_FECHA_ANTERIOR;
        }

        return $dia;
    }

    /** Nota de sistema en la oportunidad (patrón de OportunidadController::cobro). */
    private function nota(CrmOportunidad $oportunidad, string $descripcion): CrmActividad
    {
        return CrmActividad::create([
            'empresa_id' => $oportunidad->empresa_id,
            'tipo' => 'nota',
            'entidad_type' => CrmOportunidad::class,
            'entidad_id' => $oportunidad->id,
            'vendedor_id' => $oportunidad->vendedor_id,
            'descripcion' => $descripcion,
            'fecha_actividad' => now(),
            'fuente' => 'sistema',
        ]);
    }

    /** «Calidad › Madurez». */
    private function etiquetaMotivo(int $motivoId): string
    {
        $motivo = CrmMotivoRechazo::with('categoria:id,nombre')->find($motivoId);

        return "{$motivo?->categoria?->nombre} › {$motivo?->nombre}";
    }

    /** «Mango Ataulfo Calibre 9»: producto y calibre del renglón copiados al vender. */
    private function descripcionRenglon(CrmOportunidadVentaRenglon $renglon): string
    {
        return trim(implode(' ', array_filter([$renglon->descripcion, $renglon->calibre])));
    }
}
