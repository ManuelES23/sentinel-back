<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCategoriaRechazo;
use App\Models\CRM\CrmCotizacion;
use App\Models\CRM\CrmMotivoRechazo;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmOportunidadAjuste;
use App\Models\CRM\CrmOportunidadVenta;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registrar lo vendido de una cotización por renglón, solo SBP (spec
 * 2026-11-25 §2). Con al menos un renglón vendido la oportunidad se gana con
 * monto = lo vendido; con ninguno se pierde. Todo ocurre en una transacción con
 * la oportunidad bloqueada y el estado revalidado bajo el candado; los eventos
 * en tiempo real los difunde el controlador DESPUÉS de confirmar.
 */
class VentaOportunidadService
{
    public const MENSAJE_PERDIDA = 'La oportunidad está cerrada como perdida; no se puede registrar lo vendido.';

    public const MENSAJE_YA_REGISTRADA = 'La cotización ya tiene venta registrada; registra un ajuste en la oportunidad.';

    public const MENSAJE_CON_AJUSTES = 'Esta venta ya tiene ajustes; anúlalos antes de registrar otra.';

    public const MENSAJE_ESTADO = 'Solo una cotización enviada puede registrar lo vendido.';

    public const MENSAJE_SIN_RESULTADO = 'Indica el resultado de cada renglón de la cotización.';

    private const MENSAJE_SIN_MOTIVO = 'Elige por qué no se vendió.';

    private const MENSAJE_CAJAS = 'Las cajas deben ser un número entero mayor a cero.';

    private const MENSAJE_PRECIO = 'El precio debe ser un número de 0 en adelante, con máximo 2 decimales.';

    private const MENSAJE_VENDIDO = 'Indica si el renglón se vendió o no.';

    private const MENSAJE_NOTA = 'La nota no es válida.';

    private const MENSAJE_MONTO = 'El monto vendido excede el máximo permitido.';

    private const MENSAJE_YA_GANADA = 'La oportunidad ya está ganada; deja al menos un renglón vendido.';

    private const CAJAS_MAXIMAS = 2000000000;

    /**
     * @param  mixed  $renglones  `[{linea_id, vendido, cantidad?, precio?, motivo_rechazo_id?, nota?}]` tal cual llegó
     * @return array{oportunidad: CrmOportunidad, venta: ?CrmOportunidadVenta, actividad: CrmActividad, cotizacion: CrmCotizacion, superadas: Collection, eventos: Collection}
     *
     * @throws VentaNoRegistrableException estado que no permite registrar (422 sin campo)
     * @throws ValidationException renglones inválidos (422 con la clave del campo)
     */
    public function registrar(CrmCotizacion $cotizacion, mixed $renglones, int $userId): array
    {
        return DB::transaction(function () use ($cotizacion, $renglones, $userId) {
            // La oportunidad primero: serializa con aprobar/editar/otro registro de venta.
            $oportunidad = CrmOportunidad::whereKey($cotizacion->oportunidad_id)->lockForUpdate()->first();
            if ($oportunidad === null || $oportunidad->estaPerdida()) {
                throw new VentaNoRegistrableException(self::MENSAJE_PERDIDA);
            }

            // Estado revalidado ya con el candado: lo que se vea aquí es lo que se escribe.
            $actual = CrmCotizacion::whereKey($cotizacion->id)->lockForUpdate()->firstOrFail();
            $vigente = CrmOportunidadVenta::where('oportunidad_id', $oportunidad->id)
                ->where('vigente', true)
                ->lockForUpdate()
                ->first();

            if ($vigente !== null && (int) $vigente->cotizacion_id === (int) $actual->id) {
                throw new VentaNoRegistrableException(self::MENSAJE_YA_REGISTRADA);
            }
            if (! in_array($actual->estado, ['enviado', 'aprobado'], true)) {
                throw new VentaNoRegistrableException(self::MENSAJE_ESTADO);
            }
            if ($vigente !== null && $this->tieneAjustesVigentes($vigente)) {
                throw new VentaNoRegistrableException(self::MENSAJE_CON_AJUSTES);
            }

            $lineas = $actual->lineas()->orderBy('id')->get();
            $resultados = $this->validarRenglones($renglones, $lineas, (int) $actual->empresa_id);

            $vendidos = array_values(array_filter($resultados, fn (array $r) => $r['vendido']));
            if ($vendidos === [] && $oportunidad->etapa === 'cerrado_ganado') {
                // Una ganada no se pierde después (regla de CrmOportunidad).
                throw ValidationException::withMessages(['renglones' => self::MENSAJE_YA_GANADA]);
            }

            $descuento = (float) $actual->descuento_global_pct;
            $monto = $this->calcularMonto($vendidos, $descuento);
            if ($monto === null) {
                throw ValidationException::withMessages(['renglones' => self::MENSAJE_MONTO]);
            }

            if ($vigente !== null) {
                $vigente->update(['vigente' => false]);
            }

            $venta = CrmOportunidadVenta::create([
                'empresa_id' => $actual->empresa_id,
                'oportunidad_id' => $oportunidad->id,
                'cotizacion_id' => $actual->id,
                'vigente' => true,
                'descuento_global_pct' => $descuento,
                'registrada_por' => $userId,
            ]);
            foreach ($resultados as $r) {
                $venta->renglones()->create($r['fila']);
            }

            $superadas = new Collection;
            $eventos = new Collection;
            if ($vendidos !== []) {
                $this->ganar($oportunidad, $actual, $monto, $superadas);
                $descripcion = sprintf(
                    'Venta registrada: %s · %d de %d renglones · %s',
                    $actual->folio, count($vendidos), count($resultados), self::dinero($monto)
                );
            } else {
                $descripcion = $this->perder($oportunidad, $actual, $resultados, $eventos);
            }

            $actividad = CrmActividad::create([
                'empresa_id' => $oportunidad->empresa_id,
                'tipo' => 'nota',
                'entidad_type' => CrmOportunidad::class,
                'entidad_id' => $oportunidad->id,
                'vendedor_id' => $oportunidad->vendedor_id,
                'descripcion' => $descripcion,
                'fecha_actividad' => now(),
                'fuente' => 'sistema',
            ]);

            return [
                'oportunidad' => $oportunidad,
                'venta' => $venta->load('renglones'),
                'actividad' => $actividad,
                'cotizacion' => $actual->fresh(),
                'superadas' => $superadas,
                'eventos' => $eventos,
            ];
        });
    }

    /**
     * Monto de la venta: round(Σ cajas × precio × (1 − descuento/100), 2) sobre
     * los renglones vendidos, calculado en centavos enteros.
     */
    public function montoVenta(CrmOportunidadVenta $venta): float
    {
        $vendidos = $venta->renglones()->where('vendido', true)->get()
            ->map(fn ($r) => ['cantidad' => (int) $r->cantidad_vendida, 'precio' => (float) $r->precio_vendido])
            ->all();

        return $this->calcularMonto($vendidos, (float) $venta->descuento_global_pct) ?? 0.0;
    }

    /** `$28,500.00`; un importe negativo se escribe «−$28,500.00». */
    public static function dinero(float $monto): string
    {
        return ($monto < 0 ? '−' : '').'$'.number_format(abs($monto), 2, '.', ',');
    }

    private function tieneAjustesVigentes(CrmOportunidadVenta $venta): bool
    {
        return CrmOportunidadAjuste::where('oportunidad_id', $venta->oportunidad_id)
            ->whereIn('venta_renglon_id', $venta->renglones()->select('id'))
            ->whereNull('anulado_at')
            ->exists();
    }

    /**
     * Revisa los renglones recibidos contra los de la cotización (exactamente
     * uno por renglón) y arma lo que se guarda.
     *
     * @return list<array{vendido: bool, fila: array<string, mixed>, importe_cotizado: float, motivo_id: ?int}>
     */
    private function validarRenglones(mixed $recibidos, Collection $lineas, int $empresaId): array
    {
        $sinResultado = fn () => ValidationException::withMessages(['renglones' => self::MENSAJE_SIN_RESULTADO]);

        if (! is_array($recibidos) || $recibidos === [] || count($recibidos) !== $lineas->count()) {
            throw $sinResultado();
        }

        $porLinea = [];   // linea_id => [posición, renglón recibido]
        foreach (array_values($recibidos) as $posicion => $r) {
            $id = is_array($r) ? $this->entero($r['linea_id'] ?? null) : null;
            if ($id === null || isset($porLinea[$id]) || ! $lineas->contains('id', $id)) {
                throw $sinResultado();
            }
            $porLinea[$id] = [$posicion, $r];
        }

        $motivosValidos = CrmMotivoRechazo::activos()
            ->whereIn('categoria_id', CrmCategoriaRechazo::where('empresa_id', $empresaId)->where('activo', true)->select('id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $errores = [];
        $resultados = [];
        foreach ($lineas as $linea) {
            [$i, $r] = $porLinea[$linea->id];
            $clave = "renglones.$i";

            $vendido = filter_var($r['vendido'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($vendido === null) {
                $errores["$clave.vendido"] = self::MENSAJE_VENDIDO;

                continue;
            }

            $nota = $r['nota'] ?? null;
            if ($nota !== null && ! is_string($nota)) {
                $errores["$clave.nota"] = self::MENSAJE_NOTA;

                continue;
            }
            $nota = $nota === null ? null : trim($nota);
            if ($nota !== null && mb_strlen($nota) > 1000) {
                $errores["$clave.nota"] = self::MENSAJE_NOTA;

                continue;
            }
            $nota = $nota === '' ? null : $nota;

            $fila = [
                'cotizacion_linea_id' => $linea->id,
                'producto_id' => $linea->producto_id,
                'descripcion' => $linea->descripcion,
                'calibre' => $linea->calibre,
                'presentacion' => $linea->presentacion,
                'cantidad_cotizada' => $linea->cantidad,
                'precio_cotizado' => $linea->precio_unitario,
                'vendido' => $vendido,
                'cantidad_vendida' => null,
                'precio_vendido' => null,
                'motivo_rechazo_id' => null,
                'nota' => $nota,
            ];
            $resultado = [
                'vendido' => $vendido,
                'fila' => $fila,
                'importe_cotizado' => (float) $linea->cantidad * (float) $linea->precio_unitario,
                'motivo_id' => null,
                'cantidad' => 0,
                'precio' => 0.0,
            ];

            if ($vendido) {
                // Sin cantidad o precio se toma lo cotizado (prellenado de la pantalla).
                $cantidad = ($r['cantidad'] ?? null) === null
                    ? $this->entero($linea->cantidad)
                    : $this->entero($r['cantidad']);
                if ($cantidad === null || $cantidad < 1 || $cantidad > self::CAJAS_MAXIMAS) {
                    $errores["$clave.cantidad"] = self::MENSAJE_CAJAS;
                }

                $precio = ($r['precio'] ?? null) === null ? (float) $linea->precio_unitario : $this->precio($r['precio']);
                if ($precio === null) {
                    $errores["$clave.precio"] = self::MENSAJE_PRECIO;
                }

                if ($cantidad !== null && $precio !== null) {
                    $fila['cantidad_vendida'] = $cantidad;
                    $fila['precio_vendido'] = $precio;
                    $resultado['fila'] = $fila;
                    $resultado['cantidad'] = $cantidad;
                    $resultado['precio'] = $precio;
                }
            } else {
                $motivo = $this->entero($r['motivo_rechazo_id'] ?? null);
                if ($motivo === null || ! in_array($motivo, $motivosValidos, true)) {
                    $errores["$clave.motivo_rechazo_id"] = self::MENSAJE_SIN_MOTIVO;
                } else {
                    $resultado['fila']['motivo_rechazo_id'] = $motivo;
                    $resultado['motivo_id'] = $motivo;
                }
            }

            $resultados[] = $resultado;
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $resultados;
    }

    /**
     * Gana la oportunidad igual que CotizacionController::aprobar (la aprobada
     * anterior pasa a superado; una ya ganada conserva su fecha de cierre) pero
     * con monto = lo vendido.
     */
    private function ganar(CrmOportunidad $oportunidad, CrmCotizacion $cotizacion, float $monto, Collection $superadas): void
    {
        $previas = CrmCotizacion::where('oportunidad_id', $oportunidad->id)
            ->where('id', '!=', $cotizacion->id)
            ->where('estado', 'aprobado')
            ->get();
        foreach ($previas as $previa) {
            $previa->update(['estado' => 'superado']);
            $superadas->push($previa);
        }

        $cotizacion->update(['estado' => 'aprobado']);

        $yaGanada = $oportunidad->etapa === 'cerrado_ganado';
        $oportunidad->etapa = 'cerrado_ganado';
        if (! $yaGanada) {
            $oportunidad->fecha_cierre_real = now();
        }
        $oportunidad->monto_esperado = $monto;
        $oportunidad->save();
    }

    /**
     * Pierde la oportunidad (ningún renglón se vendió) con el motivo del
     * renglón de mayor importe cotizado y cierra su agenda pendiente como
     * CambiarEtapa. Devuelve el texto de la nota de sistema.
     *
     * @param  list<array<string, mixed>>  $resultados
     */
    private function perder(CrmOportunidad $oportunidad, CrmCotizacion $cotizacion, array $resultados, Collection &$eventos): string
    {
        $mayor = $resultados[0];
        foreach ($resultados as $r) {
            if ($r['importe_cotizado'] > $mayor['importe_cotizado']) {
                $mayor = $r;
            }
        }

        $cotizacion->update(['estado' => 'rechazado']);

        $oportunidad->etapa = 'cerrado_perdido';
        $oportunidad->motivo_perdida = "Ningún renglón se vendió ({$cotizacion->folio}).";
        $oportunidad->motivo_rechazo_id = $mayor['motivo_id'];
        $oportunidad->fecha_cierre_real = now();
        $oportunidad->save();

        $eventos = CrmAgenda::pendientesDeOportunidad($oportunidad)->get();
        $eventos->each->update(['completado' => true]);

        $motivo = CrmMotivoRechazo::with('categoria:id,nombre')->find($mayor['motivo_id']);

        return sprintf(
            'Ningún renglón se vendió (%s) · %s › %s',
            $cotizacion->folio, $motivo?->categoria?->nombre, $motivo?->nombre
        );
    }

    /**
     * Total en centavos enteros: Σ cajas × precio, menos el descuento global
     * (en centésimas de punto), redondeado al centavo. Null si no cabe en
     * monto_esperado (decimal(12,2)).
     *
     * @param  list<array{cantidad: int, precio: float}>  $vendidos
     */
    private function calcularMonto(array $vendidos, float $descuento): ?float
    {
        $centavos = 0;
        foreach ($vendidos as $v) {
            $centavos += $v['cantidad'] * (int) round($v['precio'] * 100);
            if (! is_int($centavos) || $centavos > (int) round(CrmOportunidad::MONTO_MAXIMO * 100)) {
                return null;
            }
        }

        $centesimas = (int) round($descuento * 100);
        $neto = intdiv($centavos * (10000 - $centesimas) + 5000, 10000);

        return $neto / 100;
    }

    /** Entero ≥ 0 o negativo escrito como entero (1, "1", 1.0); null si no es un entero. */
    private function entero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }
        if (is_float($valor)) {
            return floor($valor) === $valor && abs($valor) < PHP_INT_MAX ? (int) $valor : null;
        }
        if (is_string($valor) && preg_match('/^-?\d{1,15}(\.0+)?$/', trim($valor))) {
            return (int) $valor;
        }

        return null;
    }

    /** Precio ≥ 0 con a lo más 2 decimales y dentro de decimal(12,2); null si no. */
    private function precio(mixed $valor): ?float
    {
        if (is_string($valor)) {
            $valor = trim($valor);
        }
        if (! is_int($valor) && ! is_float($valor) && ! (is_string($valor) && is_numeric($valor))) {
            return null;
        }

        $precio = (float) $valor;
        if ($precio < 0 || $precio > CrmOportunidad::MONTO_MAXIMO || abs($precio * 100 - round($precio * 100)) > 0.0001) {
            return null;
        }

        return round($precio, 2);
    }
}
