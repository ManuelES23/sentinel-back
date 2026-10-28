<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\ProgramaCambio;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Secciones del Programa semanal que se derivan del plan efectivo: receta de
 * cada renglón, programa de empaque, logística, opciones de falta, cambios
 * posteriores a la junta y clientes de semanas previas.
 */
class SeccionesSemana
{
    private const MAX_OPCIONES = 5;

    private const MAX_CAMBIOS = 100;

    private const SEMANAS_CLIENTES_PREVIOS = 4;

    public function __construct(private readonly CatalogoPlaneacion $catalogo) {}

    /** Estado de la receta de un renglón: sin_empaque, sin_receta u ok. */
    public function receta(ProgramaNecesidad $n): array
    {
        if ($n->tipo_empaque_id === null) {
            return ['estado' => 'sin_empaque', 'receta' => null];
        }

        $receta = $this->catalogo->receta($n->presentacion_id, $n->tipo_empaque_id, $n->variedades->pluck('id')->all());

        return ['estado' => $receta ? 'ok' : 'sin_receta', 'receta' => $receta];
    }

    /**
     * Programa de empaque: necesidades efectivas por (presentación, grupo,
     * tipo de empaque, receta). Los renglones sin empaque van como
     * «sin_clasificar» y los que no tienen receta como «sin_receta».
     */
    public function empaque(array $necesidades): array
    {
        $renglones = [];
        foreach ($necesidades as $renglon) {
            if (array_sum($renglon['cajas']) === 0) {
                continue;
            }

            $n = $renglon['necesidad'];
            $receta = $this->receta($n);
            $clase = match ($receta['estado']) {
                'sin_empaque' => 'sin_clasificar',
                'sin_receta' => 'sin_receta',
                default => 'ok',
            };
            $llave = implode('|', [$n->presentacion_id, $renglon['grupo'], $n->tipo_empaque_id, $receta['receta']['id'] ?? '']);

            $renglones[$llave] ??= [
                'presentacion_id' => $n->presentacion_id,
                'grupo_id' => $renglon['grupo'] !== null ? CatalogoPlaneacion::idPublico($renglon['grupo']) : null,
                'tipo_empaque_id' => $n->tipo_empaque_id,
                'receta_id' => $receta['receta']['id'] ?? null,
                'receta_nombre' => $receta['receta']['nombre'] ?? null,
                'clase' => $clase,
                'cajas' => array_fill(0, CalculadoraBalance::DIAS, 0),
            ];
            foreach ($renglon['cajas'] as $dia => $cajas) {
                $renglones[$llave]['cajas'][$dia] += $cajas;
            }
        }

        // Los que tienen receta primero; los sin clasificar al final.
        $orden = ['ok' => 0, 'sin_receta' => 1, 'sin_clasificar' => 2];
        $salida = array_values($renglones);
        usort($salida, fn ($a, $b) => $orden[$a['clase']] <=> $orden[$b['clase']]);

        return $salida;
    }

    /** Pedido (necesidades efectivas) contra planeado (viajes) por bodega, cultivo y día. */
    public function logistica(array $necesidades, Collection $viajes): array
    {
        $ceros = array_fill(0, CalculadoraBalance::DIAS, 0);
        $filas = [];
        $fila = function (int $bodegaId, int $cultivoId) use (&$filas, $ceros): string {
            $llave = $bodegaId.'|'.$cultivoId;
            $filas[$llave] ??= ['bodega_id' => $bodegaId, 'cultivo_id' => $cultivoId, 'pedido' => $ceros, 'planeado' => $ceros];

            return $llave;
        };

        foreach ($necesidades as $renglon) {
            $llave = $fila($renglon['necesidad']->bodega_id, $renglon['necesidad']->cultivo_id);
            foreach ($renglon['cajas'] as $dia => $cajas) {
                $filas[$llave]['pedido'][$dia] += $cajas;
            }
        }

        foreach ($viajes as $viaje) {
            if ($viaje->dia >= 0 && $viaje->dia < CalculadoraBalance::DIAS) {
                $llave = $fila($viaje->bodega_id, $viaje->cultivo_id);
                // Un sencillo lleva una sola caja de tráiler: cajas_caja_2 no cuenta aunque traiga valor.
                $filas[$llave]['planeado'][$viaje->dia] += (int) $viaje->cajas_caja_1 + ($viaje->tipo === 'full' ? (int) $viaje->cajas_caja_2 : 0);
            }
        }

        return array_values($filas);
    }

    /**
     * Para cada grupo con falta, en su primer día con falta: etapas de sus
     * variedades que cosechan del domingo a 14 días después y que se podrían
     * adelantar, y los pedidos más grandes de ese día que se podrían mover.
     */
    public function opcionesFalta(array $balance, array $necesidades, array $candidatas, string $desde, string $hasta): array
    {
        $opciones = [];
        foreach ($balance as $clave => $g) {
            if ($g['estado'] !== 'falta') {
                continue;
            }

            $clave = (string) $clave;
            $dia = $g['primer_dia_falta'];

            $etapas = array_values(array_filter($candidatas, fn ($e) => $e['variedad_id'] !== null
                && $this->catalogo->grupoDeVariedad($e['variedad_id']) === $clave
                && $e['fecha_cosecha'] >= $desde
                && $e['fecha_cosecha'] <= $hasta
                && $e['ha_disponibles'] > 0));

            $pedidos = [];
            foreach ($necesidades as $renglon) {
                if ($renglon['id'] > 0 && $renglon['grupo'] === $clave && $renglon['cajas'][$dia] > 0) {
                    $pedidos[] = [
                        'necesidad_id' => $renglon['id'],
                        'cliente_nombre' => $renglon['necesidad']->cliente_nombre,
                        'cajas' => $renglon['cajas'][$dia],
                    ];
                }
            }
            usort($pedidos, fn ($a, $b) => $b['cajas'] <=> $a['cajas']);

            $opciones[$clave] = [
                'dia' => $dia,
                'etapas' => array_slice($etapas, 0, self::MAX_OPCIONES),
                'pedidos' => array_slice($pedidos, 0, self::MAX_OPCIONES),
            ];
        }

        return $opciones;
    }

    /** Cambios posteriores a la junta registrada, recientes primero. */
    public function cambios(ProgramaSemanal $programa): array
    {
        if ($programa->junta_registrada_at === null) {
            return [];
        }

        return ProgramaCambio::with('usuario:id,name')
            ->where('programa_id', $programa->id)
            ->where('created_at', '>=', $programa->junta_registrada_at)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_CAMBIOS)
            ->get()
            ->map(fn (ProgramaCambio $c) => [
                'id' => $c->id,
                'entidad' => $c->entidad,
                'entidad_id' => $c->entidad_id,
                'accion' => $c->accion,
                'resumen' => $this->resumenCambio($c),
                'usuario' => $c->usuario?->name,
                'created_at' => $c->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function resumenCambio(ProgramaCambio $c): string
    {
        $antes = $c->antes ?? [];
        $despues = $c->despues ?? [];
        $texto = fn ($v) => $v === null ? '—' : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));

        if ($c->accion === 'actualizado') {
            $partes = [];
            foreach (array_unique(array_merge(array_keys($antes), array_keys($despues))) as $campo) {
                $a = $antes[$campo] ?? null;
                $d = $despues[$campo] ?? null;
                if ($a != $d) {
                    $partes[] = "{$campo}: {$texto($a)} → {$texto($d)}";
                }
            }

            return $partes ? implode('; ', $partes) : 'Sin cambios de valor';
        }

        $titulo = ucfirst(str_replace('_', ' ', $c->entidad)).' '.$c->accion;

        // Al crear se muestran los valores con que quedó.
        if ($c->accion === 'creado' && $despues) {
            $partes = [];
            foreach ($despues as $campo => $valor) {
                $partes[] = "{$campo}: {$texto($valor)}";
            }

            return $titulo.' ('.implode('; ', $partes).')';
        }

        return $titulo;
    }

    /**
     * Clientes (con su bodega) que pidieron en las 4 semanas previas y que no
     * tienen renglón en esta. Sin id del CRM, el cliente se reconoce por nombre.
     */
    public function clientesPrevios(string $lunes, array $necesidades): array
    {
        $llave = fn (?int $clienteId, string $nombre, int $bodegaId) => ($clienteId !== null ? 'c'.$clienteId : 'n'.mb_strtolower(trim($nombre))).'|'.$bodegaId;

        $presentes = [];
        foreach ($necesidades as $renglon) {
            $n = $renglon['necesidad'];
            $presentes[$llave($n->cliente_id, $n->cliente_nombre, $n->bodega_id)] = true;
        }

        $programas = ProgramaSemanal::whereDate('lunes', '>=', Carbon::parse($lunes)->subWeeks(self::SEMANAS_CLIENTES_PREVIOS)->toDateString())
            ->whereDate('lunes', '<', $lunes)
            ->orderByDesc('lunes')
            ->pluck('id');
        if ($programas->isEmpty()) {
            return [];
        }
        $orden = $programas->flip();

        $previos = [];
        ProgramaNecesidad::whereIn('programa_id', $programas)
            ->get(['id', 'programa_id', 'cliente_id', 'cliente_nombre', 'bodega_id'])
            ->sortBy(fn ($n) => [$orden[$n->programa_id], -$n->id]) // el nombre más reciente gana
            ->each(function (ProgramaNecesidad $n) use (&$previos, $presentes, $llave) {
                $clave = $llave($n->cliente_id, $n->cliente_nombre, $n->bodega_id);
                if (! isset($presentes[$clave]) && ! isset($previos[$clave])) {
                    $previos[$clave] = [
                        'cliente_id' => $n->cliente_id,
                        'cliente_nombre' => $n->cliente_nombre,
                        'bodega_id' => $n->bodega_id,
                    ];
                }
            });

        $salida = array_values($previos);
        usort($salida, fn ($a, $b) => [mb_strtolower($a['cliente_nombre']), $a['bodega_id']] <=> [mb_strtolower($b['cliente_nombre']), $b['bodega_id']]);

        return $salida;
    }
}
