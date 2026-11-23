<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaSobrante;
use Illuminate\Support\Carbon;

/**
 * Secciones del Programa semanal que se derivan del plan: programa de empaque
 * por destino y clientes de semanas previas.
 */
class SeccionesSemana
{
    private const SEMANAS_CLIENTES_PREVIOS = 4;

    /** Orden de las clases de empaque: ventas clasificadas, sobrante y «sin_clasificar» al final. */
    private const ORDEN_CLASE = ['ok' => 0, 'sobrante' => 1, 'sin_clasificar' => 2];

    /**
     * Programa de empaque: necesidades por destino (bodega), presentación,
     * grupo de balance y tipo de empaque. Los renglones sin empaque caen en
     * «sin_clasificar» con `tipo_empaque_id` null (en el Excel deben dar 0).
     * El sobrante asignado sale en sus propios renglones (`clase = sobrante`),
     * nunca juntos con los de venta aunque compartan la llave.
     *
     * Las necesidades llegan por día de empaque (PlanSemana::necesidadesPorEmpaque()):
     * incluyen las de la semana siguiente que se empacan en esta, con la misma forma.
     *
     * @param  list<array{necesidad: ProgramaNecesidad, grupo: ?string, cajas: int[]}>  $necesidades  Salida de PlanSemana::necesidadesPorEmpaque().
     * @param  list<array{sobrante: ProgramaSobrante, grupo: ?string, cajas: int[]}>  $sobrantes  Salida de PlanSemana::sobrantes().
     * @return list<array{bodega_id: int, presentacion_id: int, grupo_id: int|string|null, tipo_empaque_id: ?int, clase: string, cajas: int[]}>
     */
    public function empaque(array $necesidades, array $sobrantes = []): array
    {
        $renglones = [];
        $agregar = function (string $origen, ProgramaNecesidad|ProgramaSobrante $n, ?string $grupo, array $cajasPorDia, string $clase) use (&$renglones) {
            if (array_sum($cajasPorDia) === 0) {
                return;
            }

            $llave = implode('|', [$origen, $n->bodega_id, $n->presentacion_id, $grupo, $n->tipo_empaque_id]);

            $renglones[$llave] ??= [
                'bodega_id' => $n->bodega_id,
                'presentacion_id' => $n->presentacion_id,
                'grupo_id' => $grupo !== null ? CatalogoPlaneacion::idPublico($grupo) : null,
                'tipo_empaque_id' => $n->tipo_empaque_id,
                'clase' => $clase,
                'cajas' => array_fill(0, CalculadoraBalance::DIAS, 0),
            ];
            foreach ($cajasPorDia as $dia => $cajas) {
                $renglones[$llave]['cajas'][$dia] += $cajas;
            }
        };

        foreach ($necesidades as $renglon) {
            $n = $renglon['necesidad'];
            $agregar('venta', $n, $renglon['grupo'], $renglon['cajas'], $n->tipo_empaque_id === null ? 'sin_clasificar' : 'ok');
        }
        foreach ($sobrantes as $renglon) {
            $agregar('sobrante', $renglon['sobrante'], $renglon['grupo'], $renglon['cajas'], 'sobrante');
        }

        $salida = array_values($renglones);
        usort($salida, fn ($a, $b) => self::ORDEN_CLASE[$a['clase']] <=> self::ORDEN_CLASE[$b['clase']]);

        return $salida;
    }

    /**
     * Clientes (con su bodega) que pidieron en las 4 semanas previas y que no
     * tienen renglón en esta. El cliente se reconoce por su id de la lista de
     * Planeación; un renglón viejo sin liga, por el id del CRM o por la clave
     * de su nombre. Un renglón de esta semana cubre a uno previo si coincide
     * por cualquiera de sus llaves (así uno ligado cubre a uno viejo sin liga).
     */
    public function clientesPrevios(string $lunes, array $necesidades): array
    {
        $llaves = function (ProgramaNecesidad $n): array {
            $sufijo = '|'.$n->bodega_id;
            $porNombre = 'n'.ClientesPlaneacion::clave((string) $n->cliente_nombre).$sufijo;
            $principal = match (true) {
                $n->plan_cliente_id !== null => 'p'.$n->plan_cliente_id.$sufijo,
                $n->cliente_id !== null => 'c'.$n->cliente_id.$sufijo,
                default => $porNombre,
            };

            return array_values(array_unique([$principal, $porNombre]));
        };

        $presentes = [];
        foreach ($necesidades as $renglon) {
            foreach ($llaves($renglon['necesidad']) as $clave) {
                $presentes[$clave] = true;
            }
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
        $vistos = [];
        ProgramaNecesidad::whereIn('programa_id', $programas)
            ->get(['id', 'programa_id', 'cliente_id', 'plan_cliente_id', 'cliente_nombre', 'bodega_id'])
            ->sortBy(fn ($n) => [$orden[$n->programa_id], -$n->id]) // el nombre más reciente gana
            ->each(function (ProgramaNecesidad $n) use (&$previos, &$vistos, $presentes, $llaves) {
                $claves = $llaves($n);
                foreach ($claves as $clave) {
                    if (isset($presentes[$clave]) || isset($vistos[$clave])) {
                        return;
                    }
                }
                foreach ($claves as $clave) {
                    $vistos[$clave] = true;
                }
                $previos[] = [
                    'cliente_id' => $n->cliente_id,
                    'plan_cliente_id' => $n->plan_cliente_id,
                    'cliente_nombre' => $n->cliente_nombre,
                    'bodega_id' => $n->bodega_id,
                ];
            });

        $salida = array_values($previos);
        usort($salida, fn ($a, $b) => [mb_strtolower($a['cliente_nombre']), $a['bodega_id']] <=> [mb_strtolower($b['cliente_nombre']), $b['bodega_id']]);

        return $salida;
    }
}
