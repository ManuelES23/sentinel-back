<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\RecetaCombinacion;
use App\Models\ProduccionEmpaque;
use App\Models\Recipe;
use Carbon\CarbonImmutable;

/**
 * Real empacado de una semana (lunes a domingo) traducido a los renglones del
 * Programa de empaque: presentación × grupo de balance × tipo de empaque, con
 * las cajas por día.
 *
 * Cuentan los pallets de `produccion_empaque` no borrados con
 * `fecha_produccion` en la semana, en cualquier estado y también las colas.
 * Un pallet con renglones en `produccion_empaque_detalles` (mixto) se reparte
 * por ellos, cada uno con su receta y sus cajas; si no, cuenta entero con su
 * propia receta (mismo criterio que los reportes de empaque y el dashboard de
 * cosecha). Un detalle sin receta hereda la del pallet, y si el pallet tiene
 * más cajas que la suma de sus detalles, la diferencia cuenta con la receta
 * del pallet (así lo mostrado cuadra siempre con las cajas de los pallets).
 * El día es siempre el de la fecha del pallet.
 *
 * Traducción de (receta, variedad del pallet): las combinaciones de
 * `plan_recetas_combinacion` de esa receta con la variedad del pallet; si no
 * hay, las de variedad nula (cualquiera). Exactamente una → su presentación y
 * su tipo de empaque; ninguna o más de una → «sin clasificar». El grupo sale
 * de la variedad del pallet (`CatalogoPlaneacion::grupoDeVariedad`). Un pallet
 * sin receta o sin variedad también queda sin clasificar.
 *
 * Con cultivo: un renglón cuenta si la presentación de su combinación es del
 * cultivo; una parte sin clasificar, si la variedad del pallet es del cultivo
 * (sin variedad no se sabe el cultivo y se cuenta, para no perderla).
 */
final class RealEmpacado
{
    public function __construct(private CatalogoPlaneacion $catalogo) {}

    /** Llave de un renglón: la misma con la que se cruza con el programa. */
    public static function llave(int $presentacionId, int|string $grupoId, ?int $tipoEmpaqueId): string
    {
        return $presentacionId.'|'.$grupoId.'|'.($tipoEmpaqueId ?? '');
    }

    /**
     * Real de la semana que empieza en `$lunes`. La fecha de un pallet mixto, y
     * con ella la de todos sus detalles, es la `fecha_produccion` del pallet
     * (igual que en los reportes por pallet): la propia del detalle no se usa.
     *
     * @return array{
     *     renglones: array<string, array{presentacion_id: int, grupo_id: int|string, tipo_empaque_id: int, cajas: int[]}>,
     *     sin_clasificar: array{cajas: int[], pallets: int, recetas: list<array{recipe_id: ?int, nombre: ?string, cajas: int}>}
     * }
     */
    public function deLaSemana(string $lunes, ?int $cultivoId): array
    {
        $inicio = CarbonImmutable::parse($lunes)->startOfDay();
        $dias = [];
        for ($d = 0; $d < CalculadoraBalance::DIAS; $d++) {
            $dias[$inicio->addDays($d)->toDateString()] = $d;
        }
        $ceros = array_fill(0, CalculadoraBalance::DIAS, 0);

        // Rango semiabierto: en SQLite las fechas se guardan «Y-m-d 00:00:00».
        $pallets = ProduccionEmpaque::query()
            ->where('fecha_produccion', '>=', $inicio->toDateString())
            ->where('fecha_produccion', '<', $inicio->addDays(CalculadoraBalance::DIAS)->toDateString())
            ->with('detalles:id,produccion_id,numero_entrada,recipe_id,total_cajas')
            ->get(['id', 'fecha_produccion', 'variedad_id', 'recipe_id', 'total_cajas']);

        // Partes: [pallet_id, día, receta, variedad, cajas].
        $partes = [];
        $recetaIds = [];
        foreach ($pallets as $pallet) {
            $dia = $dias[$pallet->fecha_produccion->toDateString()] ?? null;
            if ($dia === null) {
                continue;
            }
            $variedadId = $pallet->variedad_id !== null ? (int) $pallet->variedad_id : null;
            if ($pallet->detalles->isEmpty()) {
                $renglones = [[$pallet->recipe_id, (int) $pallet->total_cajas]];
            } else {
                // Un detalle sin receta es de la receta del pallet (así consume el resto del sistema).
                $renglones = $pallet->detalles
                    ->map(fn ($d) => [$d->recipe_id ?? $pallet->recipe_id, (int) $d->total_cajas])
                    ->all();
                // La «base» que quedó fuera de los detalles (p. ej. tras borrar una entrada)
                // va con la receta del pallet, para que el real cuadre con sus cajas.
                $remanente = (int) $pallet->total_cajas - array_sum(array_column($renglones, 1));
                if ($remanente > 0) {
                    $renglones[] = [$pallet->recipe_id, $remanente];
                }
            }

            foreach ($renglones as [$recetaId, $cajas]) {
                $recetaId = $recetaId !== null ? (int) $recetaId : null;
                $partes[] = [$pallet->id, $dia, $recetaId, $variedadId, $cajas];
                if ($recetaId !== null) {
                    $recetaIds[$recetaId] = true;
                }
            }
        }

        // receta => variedad ('*' = cualquiera) => combinaciones con el cultivo de su presentación.
        $combinaciones = [];
        if ($recetaIds) {
            $filas = RecetaCombinacion::query()
                ->join('plan_presentaciones', 'plan_presentaciones.id', '=', 'plan_recetas_combinacion.presentacion_id')
                ->whereIn('plan_recetas_combinacion.recipe_id', array_keys($recetaIds))
                ->get([
                    'plan_recetas_combinacion.recipe_id',
                    'plan_recetas_combinacion.variedad_id',
                    'plan_recetas_combinacion.presentacion_id',
                    'plan_recetas_combinacion.tipo_empaque_id',
                    'plan_presentaciones.cultivo_id as cultivo_id',
                ]);
            foreach ($filas as $c) {
                $combinaciones[(int) $c->recipe_id][$c->variedad_id ?? '*'][] = [
                    'presentacion_id' => (int) $c->presentacion_id,
                    'tipo_empaque_id' => (int) $c->tipo_empaque_id,
                    'cultivo_id' => (int) $c->cultivo_id,
                ];
            }
        }

        $variedades = $this->catalogo->variedades();
        $renglones = [];
        $sinClasificar = $ceros;
        $palletsSinClasificar = [];
        $cajasPorReceta = [];

        foreach ($partes as [$palletId, $dia, $recetaId, $variedadId, $cajas]) {
            $combinacion = $this->resolver($combinaciones, $recetaId, $variedadId);

            if ($combinacion !== null && $variedadId !== null) {
                if ($cultivoId !== null && $combinacion['cultivo_id'] !== $cultivoId) {
                    continue;
                }
                $grupoId = CatalogoPlaneacion::idPublico($this->catalogo->grupoDeVariedad($variedadId));
                $llave = self::llave($combinacion['presentacion_id'], $grupoId, $combinacion['tipo_empaque_id']);
                $renglones[$llave] ??= [
                    'presentacion_id' => $combinacion['presentacion_id'],
                    'grupo_id' => $grupoId,
                    'tipo_empaque_id' => $combinacion['tipo_empaque_id'],
                    'cajas' => $ceros,
                ];
                $renglones[$llave]['cajas'][$dia] += $cajas;

                continue;
            }

            $variedad = $variedadId !== null ? $variedades->get($variedadId) : null;
            if ($cultivoId !== null && $variedad !== null && (int) $variedad->cultivo_id !== $cultivoId) {
                continue;
            }
            $sinClasificar[$dia] += $cajas;
            $palletsSinClasificar[$palletId] = true;
            $clave = $recetaId ?? '';
            $cajasPorReceta[$clave] = ($cajasPorReceta[$clave] ?? 0) + $cajas;
        }

        $idsConNombre = array_values(array_filter(array_keys($cajasPorReceta), fn ($id) => $id !== ''));
        $nombres = $idsConNombre
            ? Recipe::withTrashed()->whereIn('id', $idsConNombre)->pluck('name', 'id')
            : collect();

        $recetas = [];
        foreach ($cajasPorReceta as $clave => $cajas) {
            $recetas[] = [
                'recipe_id' => $clave === '' ? null : (int) $clave,
                'nombre' => $clave === '' ? null : $nombres->get($clave),
                'cajas' => $cajas,
            ];
        }
        usort($recetas, fn ($a, $b) => [$b['cajas'], $a['recipe_id'] ?? 0] <=> [$a['cajas'], $b['recipe_id'] ?? 0]);

        return [
            'renglones' => $renglones,
            'sin_clasificar' => [
                'cajas' => $sinClasificar,
                'pallets' => count($palletsSinClasificar),
                'recetas' => $recetas,
            ],
        ];
    }

    /**
     * La combinación única de la receta para la variedad (la específica gana a
     * la genérica); null si no hay receta, no hay combinación o es ambigua.
     *
     * @param  array<int, array<int|string, list<array{presentacion_id: int, tipo_empaque_id: int, cultivo_id: int}>>>  $combinaciones
     * @return array{presentacion_id: int, tipo_empaque_id: int, cultivo_id: int}|null
     */
    private function resolver(array $combinaciones, ?int $recetaId, ?int $variedadId): ?array
    {
        if ($recetaId === null || ! isset($combinaciones[$recetaId])) {
            return null;
        }

        $porVariedad = $combinaciones[$recetaId];
        $candidatas = ($variedadId !== null ? ($porVariedad[$variedadId] ?? null) : null) ?? ($porVariedad['*'] ?? []);

        // Dos filas con la misma presentación y el mismo empaque dicen lo mismo: no son ambiguas.
        $distintas = [];
        foreach ($candidatas as $c) {
            $distintas[$c['presentacion_id'].'|'.$c['tipo_empaque_id']] = $c;
        }

        return count($distintas) === 1 ? reset($distintas) : null;
    }
}
