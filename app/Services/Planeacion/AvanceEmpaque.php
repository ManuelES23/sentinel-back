<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\TipoEmpaque;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Avance de empaque de una semana (lunes a domingo): el programa de empaque
 * (programado, por día de empaque) contra el real empacado, por presentación ×
 * grupo de balance × tipo de empaque, sin destino.
 *
 * Programado: los renglones `ok` y `sobrante` del programa de empaque sumando
 * las bodegas. Los `sin_clasificar` del programa (sin tipo de empaque), y los
 * que no tienen grupo, quedan aparte en `programado_sin_clasificar`. Sin
 * programa de la semana, todo el programado es cero (no se crea el programa).
 *
 * `trabaja_domingo`: si EMPAQUE trabaja el domingo de la semana (`domingo_empaque`;
 * verdadero también sin programa, porque aquí no se crea). Corte y Embarques no
 * cuentan. Sin domingo de Empaque el programado del día 6 ya
 * llega en cero (lo pasa al sábado `PlanSemana`), pero el real de los pallets
 * con `fecha_produccion` en domingo se sigue contando en `real` y `totales`.
 *
 * Atraso por renglón: la deuda de los días ya pasados con compensación, día a
 * día `deuda = max(0, deuda + programado[d] − real[d])` (un sobre-empaque
 * cubre faltantes anteriores, pero no deja saldo a favor para días después).
 * `para_hoy` = programado de hoy + atraso, solo si hoy cae en la semana. En una
 * semana futura no hay atraso; en una pasada, es el de toda la semana.
 *
 * Real: solo los pallets de las plantas que cuentan (`PlantasAvance::ids()`,
 * por omisión las propias); `plantas` las lista en orden de nombre.
 */
final class AvanceEmpaque
{
    public function __construct(
        private ProgramaSemanalService $programas,
        private RealEmpacado $real,
        private CatalogoPlaneacion $catalogo,
        private PlantasAvance $plantas = new PlantasAvance,
    ) {}

    public function de(string $lunes, ?int $cultivoId, CarbonInterface $hoy): array
    {
        $dias = CalculadoraBalance::DIAS;
        $ceros = array_fill(0, $dias, 0);

        $inicio = CarbonImmutable::parse($lunes)->startOfDay();
        $fechaHoy = CarbonImmutable::parse($hoy->toDateString())->startOfDay();
        $indiceHoy = (int) $inicio->diffInDays($fechaHoy, false);
        $diasPasados = max(0, min($dias, $indiceHoy));
        $hoyEnSemana = $indiceHoy >= 0 && $indiceHoy < $dias;

        // Bandera de Empaque (su programado es el del programa de empaque).
        $trabajaDomingo = (bool) (ProgramaSemanal::whereDate('lunes', $lunes)->value(ProgramaSemanal::columnaDomingo('empaque')) ?? true);
        $empaque = $this->programas->empaqueDeSemana($lunes);
        $plantaIds = $this->plantas->ids();
        $real = $this->real->deLaSemana($lunes, $cultivoId, $plantaIds);

        // Presentaciones citadas por el programa y por el real, de una vez.
        $presentacionIds = array_values(array_unique(array_merge(
            array_map(fn ($r) => (int) $r['presentacion_id'], $empaque),
            array_map(fn ($r) => (int) $r['presentacion_id'], $real['renglones']),
        )));
        $presentaciones = $presentacionIds
            ? Presentacion::whereIn('id', $presentacionIds)->get(['id', 'nombre', 'cultivo_id', 'orden'])->keyBy('id')
            : collect();
        $delCultivo = fn (int $presentacionId) => $cultivoId === null
            || (int) $presentaciones->get($presentacionId)?->cultivo_id === $cultivoId;

        // Programado sin bodega.
        $programado = [];
        $programadoSinClasificar = $ceros;
        foreach ($empaque as $r) {
            if (! $delCultivo((int) $r['presentacion_id'])) {
                continue;
            }
            if ($r['clase'] === 'sin_clasificar' || $r['grupo_id'] === null || $r['tipo_empaque_id'] === null) {
                foreach ($r['cajas'] as $d => $cajas) {
                    $programadoSinClasificar[$d] += $cajas;
                }

                continue;
            }
            $llave = RealEmpacado::llave((int) $r['presentacion_id'], $r['grupo_id'], (int) $r['tipo_empaque_id']);
            $programado[$llave] ??= [
                'presentacion_id' => (int) $r['presentacion_id'],
                'grupo_id' => $r['grupo_id'],
                'tipo_empaque_id' => (int) $r['tipo_empaque_id'],
                'cajas' => $ceros,
            ];
            foreach ($r['cajas'] as $d => $cajas) {
                $programado[$llave]['cajas'][$d] += $cajas;
            }
        }

        // Cruce por llave.
        $renglones = [];
        foreach ($programado as $llave => $p) {
            $realFila = $real['renglones'][$llave]['cajas'] ?? $ceros;
            $atraso = 0;
            for ($d = 0; $d < $diasPasados; $d++) {
                $atraso = max(0, $atraso + $p['cajas'][$d] - $realFila[$d]);
            }
            $renglones[] = [
                'presentacion_id' => $p['presentacion_id'],
                'grupo_id' => $p['grupo_id'],
                'tipo_empaque_id' => $p['tipo_empaque_id'],
                'programado' => $p['cajas'],
                'real' => $realFila,
                'atraso' => $atraso,
                'para_hoy' => $hoyEnSemana ? $p['cajas'][$indiceHoy] + $atraso : null,
            ];
        }
        $sinPrograma = [];
        foreach ($real['renglones'] as $llave => $r) {
            if (isset($programado[$llave])) {
                continue;
            }
            $sinPrograma[] = [
                'presentacion_id' => $r['presentacion_id'],
                'grupo_id' => $r['grupo_id'],
                'tipo_empaque_id' => $r['tipo_empaque_id'],
                'programado' => $ceros,
                'real' => $r['cajas'],
                'atraso' => 0,
                'para_hoy' => null,
            ];
        }

        // Catálogos de lo que se muestra.
        $filas = array_merge($renglones, $sinPrograma);
        $tipoIds = array_values(array_unique(array_column($filas, 'tipo_empaque_id')));
        $tipos = $tipoIds
            ? TipoEmpaque::whereIn('id', $tipoIds)->orderBy('orden')->orderBy('id')->get(['id', 'nombre', 'orden'])->keyBy('id')
            : collect();
        $nombresGrupo = $this->nombresDeGrupos($filas, $presentaciones);

        $orden = function (array $f) use ($presentaciones, $tipos, $nombresGrupo) {
            $p = $presentaciones->get($f['presentacion_id']);
            $t = $tipos->get($f['tipo_empaque_id']);
            $grupo = (string) $f['grupo_id'];

            return [
                (int) ($p?->orden ?? PHP_INT_MAX), $f['presentacion_id'],
                mb_strtolower($nombresGrupo[$grupo] ?? ''), $grupo,
                (int) ($t?->orden ?? PHP_INT_MAX), $f['tipo_empaque_id'],
            ];
        };
        $comparar = fn ($a, $b) => $orden($a) <=> $orden($b);
        usort($renglones, $comparar);
        usort($sinPrograma, $comparar);

        $presentacionesMostradas = array_unique(array_column($filas, 'presentacion_id'));
        $catalogoPresentaciones = $presentaciones
            ->filter(fn ($p) => in_array($p->id, $presentacionesMostradas, true))
            ->sortBy(fn ($p) => [(int) $p->orden, $p->id])
            ->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre, 'cultivo_id' => (int) $p->cultivo_id, 'orden' => (int) $p->orden])
            ->values()->all();
        $catalogoGrupos = [];
        foreach ($filas as $f) {
            $clave = (string) $f['grupo_id'];
            $catalogoGrupos[$clave] ??= ['id' => $f['grupo_id'], 'nombre' => $nombresGrupo[$clave] ?? null];
        }
        uasort($catalogoGrupos, fn ($a, $b) => [mb_strtolower((string) $a['nombre']), (string) $a['id']] <=> [mb_strtolower((string) $b['nombre']), (string) $b['id']]);

        $sumaFilas = fn (array $lista, string $campo) => array_sum(array_map(fn ($f) => array_sum($f[$campo]), $lista));

        return [
            'lunes' => $inicio->toDateString(),
            'hoy' => $fechaHoy->toDateString(),
            'dias_pasados' => $diasPasados,
            'trabaja_domingo' => $trabajaDomingo,
            'renglones' => $renglones,
            'sin_programa' => $sinPrograma,
            'sin_clasificar' => $real['sin_clasificar'],
            'programado_sin_clasificar' => $programadoSinClasificar,
            'plantas' => PlantasAvance::conNombre($plantaIds),
            'totales' => [
                'programado' => $sumaFilas($renglones, 'programado') + array_sum($programadoSinClasificar),
                'real' => $sumaFilas($renglones, 'real') + $sumaFilas($sinPrograma, 'real') + array_sum($real['sin_clasificar']['cajas']),
                'atraso' => array_sum(array_column($renglones, 'atraso')),
                'para_hoy' => $hoyEnSemana ? array_sum(array_column($renglones, 'para_hoy')) : null,
            ],
            'catalogos' => [
                'presentaciones' => $catalogoPresentaciones,
                'grupos' => array_values($catalogoGrupos),
                'tipos_empaque' => $tipos->map(fn ($t) => ['id' => $t->id, 'nombre' => $t->nombre, 'orden' => (int) $t->orden])->values()->all(),
            ],
        ];
    }

    /**
     * Nombre de cada grupo citado (clave pública en texto => nombre), con la
     * forma de grupos de `GET programa` y, si no aparece ahí (p. ej. variedad
     * borrada), el nombre del grupo o de la variedad del catálogo.
     *
     * @return array<string, ?string>
     */
    private function nombresDeGrupos(array $filas, $presentaciones): array
    {
        if ($filas === []) {
            return [];
        }

        $cultivoIds = array_values(array_unique(array_map(
            fn ($f) => (int) $presentaciones->get($f['presentacion_id'])?->cultivo_id,
            $filas,
        )));
        $nombres = [];
        foreach ($this->catalogo->gruposDeCultivos($cultivoIds) as $g) {
            $nombres[(string) $g['id']] = $g['nombre'];
        }

        foreach ($filas as $f) {
            $clave = (string) $f['grupo_id'];
            if (array_key_exists($clave, $nombres)) {
                continue;
            }
            $nombres[$clave] = str_starts_with($clave, 'v')
                ? $this->catalogo->variedades()->get((int) substr($clave, 1))?->nombre
                : $this->catalogo->grupos()->get((int) $clave)?->nombre;
        }

        return $nombres;
    }
}
