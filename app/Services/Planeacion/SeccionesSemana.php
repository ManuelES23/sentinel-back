<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use Illuminate\Support\Carbon;

/**
 * Secciones del Programa semanal que se derivan del plan: programa de empaque
 * por destino y clientes de semanas previas.
 */
class SeccionesSemana
{
    private const SEMANAS_CLIENTES_PREVIOS = 4;

    /** Orden de las clases de empaque: las clasificadas primero, «sin_clasificar» al final. */
    private const ORDEN_CLASE = ['ok' => 0, 'sin_clasificar' => 1];

    /**
     * Programa de empaque: necesidades por destino (bodega), presentación,
     * grupo de balance y tipo de empaque. Los renglones sin empaque caen en
     * «sin_clasificar» con `tipo_empaque_id` null (en el Excel deben dar 0).
     *
     * @param  list<array{necesidad: ProgramaNecesidad, grupo: ?string, cajas: int[]}>  $necesidades  Salida de PlanSemana::necesidades().
     * @return list<array{bodega_id: int, presentacion_id: int, grupo_id: int|string|null, tipo_empaque_id: ?int, clase: string, cajas: int[]}>
     */
    public function empaque(array $necesidades): array
    {
        $renglones = [];
        foreach ($necesidades as $renglon) {
            if (array_sum($renglon['cajas']) === 0) {
                continue;
            }

            $n = $renglon['necesidad'];
            $llave = implode('|', [$n->bodega_id, $n->presentacion_id, $renglon['grupo'], $n->tipo_empaque_id]);

            $renglones[$llave] ??= [
                'bodega_id' => $n->bodega_id,
                'presentacion_id' => $n->presentacion_id,
                'grupo_id' => $renglon['grupo'] !== null ? CatalogoPlaneacion::idPublico($renglon['grupo']) : null,
                'tipo_empaque_id' => $n->tipo_empaque_id,
                'clase' => $n->tipo_empaque_id === null ? 'sin_clasificar' : 'ok',
                'cajas' => array_fill(0, CalculadoraBalance::DIAS, 0),
            ];
            foreach ($renglon['cajas'] as $dia => $cajas) {
                $renglones[$llave]['cajas'][$dia] += $cajas;
            }
        }

        $salida = array_values($renglones);
        usort($salida, fn ($a, $b) => self::ORDEN_CLASE[$a['clase']] <=> self::ORDEN_CLASE[$b['clase']]);

        return $salida;
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
