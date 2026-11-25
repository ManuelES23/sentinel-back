<?php

namespace App\Services\Planeacion;

use App\Models\Consignatario;
use App\Models\EmbarqueEmpaque;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Temporada;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Avance de embarques de una semana (lunes a domingo): las cargas programadas
 * (`programa_embarques`, Full + Sencillo por bodega y día) contra los embarques
 * que ya salieron. Se compara en cargas totales, no por tipo.
 *
 * Plan: los renglones del programa de la semana (filtrados por cultivo) por
 * `bodega_id` y `dia`. Sin programa el plan es cero y no se crea el programa.
 *
 * Real: `embarques_empaque` no borrados, con `fecha_embarque` en la semana,
 * status `cargando`, `en_transito` o `entregado` (nunca `programado` ni
 * `cancelado`) y de una planta del avance (`PlantasAvance::ids()`). Un embarque
 * es una carga; sus cajas son `total_cajas`. El cultivo de un embarque es el de
 * su temporada.
 *
 * Destino real: la bodega del consignatario de destino si la tiene (no vacía);
 * si no, el texto `destino`. Se compara con las bodegas de Planeación (todas,
 * activas o no) por `ClientesPlaneacion::clave()`. Con plan va a `renglones`;
 * reconocido sin plan, a `sin_programa`; sin reconocer (o sin destino), a
 * `sin_destino`, agrupado por destino.
 *
 * `trabaja_domingo` lee `domingo_embarques` (verdadero sin programa). Sin domingo
 * el real de ese día se cuenta y se muestra igual.
 *
 * Atraso por renglón: ver `AtrasoSemana::deuda` (con compensación, en cargas
 * enteras). `para_hoy` = plan de hoy + atraso, solo si hoy cae en la semana; en
 * una semana futura no hay atraso y en una pasada es el de toda la semana.
 */
final class AvanceEmbarques
{
    public const ESTADOS_REALES = ['cargando', 'en_transito', 'entregado'];

    public function __construct(private PlantasAvance $plantas = new PlantasAvance) {}

    public function de(string $lunes, ?int $cultivoId, CarbonInterface $hoy): array
    {
        $dias = CalculadoraBalance::DIAS;
        $ceros = array_fill(0, $dias, 0);

        $inicio = CarbonImmutable::parse($lunes)->startOfDay();
        $finExclusivo = $inicio->addDays($dias)->toDateString();
        $semana = AtrasoSemana::dias($lunes, $hoy);
        $hoyEnSemana = $semana['hoy_en_semana'];

        $programa = ProgramaSemanal::whereDate('lunes', $lunes)->first(['id', 'domingo_embarques']);
        $trabajaDomingo = $programa ? $programa->trabajaDomingo('embarques') : true;

        // Plan: cargas por bodega, tipo y día.
        $full = [];
        $sencillo = [];
        $conPlan = [];
        $celdas = $programa
            ? ProgramaEmbarque::where('programa_id', $programa->id)
                ->when($cultivoId !== null, fn ($q) => $q->where('cultivo_id', $cultivoId))
                ->get(['bodega_id', 'tipo', 'dia', 'cargas'])
            : collect();
        foreach ($celdas as $c) {
            $dia = (int) $c->dia;
            if ($dia < 0 || $dia >= $dias) {
                continue;
            }
            $bodegaId = (int) $c->bodega_id;
            $conPlan[$bodegaId] = true;
            if ($c->tipo === 'sencillo') {
                $sencillo[$bodegaId] ??= $ceros;
                $sencillo[$bodegaId][$dia] += (int) $c->cargas;
            } else {
                $full[$bodegaId] ??= $ceros;
                $full[$bodegaId][$dia] += (int) $c->cargas;
            }
        }

        // Bodegas de Planeación (todas) y su mapa por clave de nombre.
        $bodegas = BodegaDestino::orderBy('orden')->orderBy('id')->get(['id', 'nombre'])->keyBy('id');
        $porClave = [];
        foreach ($bodegas as $bodega) {
            $clave = ClientesPlaneacion::clave((string) $bodega->nombre);
            if ($clave !== '') {
                $porClave[$clave] ??= (int) $bodega->id;
            }
        }

        // Real: una carga por embarque salido, a su bodega o a «sin destino».
        $plantaIds = $this->plantas->ids();
        $embarques = $plantaIds === []
            ? collect()
            : EmbarqueEmpaque::query()
                ->where('fecha_embarque', '>=', $lunes)
                ->where('fecha_embarque', '<', $finExclusivo)
                ->whereIn('status', self::ESTADOS_REALES)
                ->whereIn('entity_id', $plantaIds)
                ->when($cultivoId !== null, fn ($q) => $q->whereIn(
                    'temporada_id',
                    Temporada::withTrashed()->where('cultivo_id', $cultivoId)->select('id'),
                ))
                ->orderBy('id')
                ->get(['id', 'fecha_embarque', 'destino', 'destino_consignatario_id', 'total_cajas']);

        $consignatarioIds = $embarques->pluck('destino_consignatario_id')->filter()->unique()->values()->all();
        $bodegaDeConsignatario = $consignatarioIds
            ? Consignatario::withTrashed()->whereIn('id', $consignatarioIds)->pluck('bodega', 'id')
            : collect();

        $real = [];
        $cajas = [];
        $sinDestino = ['cargas' => $ceros, 'cajas' => $ceros, 'destinos' => []];
        foreach ($embarques as $e) {
            $fecha = $e->fecha_embarque instanceof CarbonInterface
                ? $e->fecha_embarque->toDateString()
                : substr((string) $e->fecha_embarque, 0, 10);
            $dia = (int) $inicio->diffInDays(CarbonImmutable::parse($fecha)->startOfDay(), false);
            if ($dia < 0 || $dia >= $dias) {
                continue;
            }
            $total = (int) $e->total_cajas;

            $bodegaConsignatario = trim((string) $bodegaDeConsignatario->get($e->destino_consignatario_id, ''));
            $texto = trim($bodegaConsignatario !== '' ? $bodegaConsignatario : (string) $e->destino);
            $clave = ClientesPlaneacion::clave($texto);
            $bodegaId = $porClave[$clave] ?? null;

            if ($bodegaId !== null) {
                $real[$bodegaId] ??= $ceros;
                $cajas[$bodegaId] ??= $ceros;
                $real[$bodegaId][$dia]++;
                $cajas[$bodegaId][$dia] += $total;

                continue;
            }

            $sinDestino['cargas'][$dia]++;
            $sinDestino['cajas'][$dia] += $total;
            $grupo = $clave === '' ? '' : $clave;
            $sinDestino['destinos'][$grupo] ??= ['destino' => $clave === '' ? 'Sin destino' : $texto, 'cargas' => 0];
            $sinDestino['destinos'][$grupo]['cargas']++;
        }
        $destinos = array_values($sinDestino['destinos']);
        usort($destinos, fn (array $a, array $b) => [$b['cargas'], mb_strtolower($a['destino'])] <=> [$a['cargas'], mb_strtolower($b['destino'])]);
        $sinDestino['destinos'] = $destinos;

        $fila = function (int $bodegaId, bool $programada) use ($bodegas, $full, $sencillo, $real, $cajas, $ceros, $semana): array {
            $fullFila = $programada ? ($full[$bodegaId] ?? $ceros) : $ceros;
            $sencilloFila = $programada ? ($sencillo[$bodegaId] ?? $ceros) : $ceros;
            $planFila = array_map(fn ($f, $s) => $f + $s, $fullFila, $sencilloFila);
            $realFila = $real[$bodegaId] ?? $ceros;
            $atraso = $programada ? (int) AtrasoSemana::deuda($planFila, $realFila, $semana['dias_pasados']) : 0;

            return [
                'bodega_id' => $bodegaId,
                'nombre' => $bodegas->get($bodegaId)?->nombre,
                'plan' => $planFila,
                'full' => $fullFila,
                'sencillo' => $sencilloFila,
                'real' => $realFila,
                'cajas' => $cajas[$bodegaId] ?? $ceros,
                'atraso' => $atraso,
                'para_hoy' => $programada && $semana['hoy_en_semana'] ? $planFila[$semana['indice_hoy']] + $atraso : null,
            ];
        };

        // Filas en el orden de las bodegas (`orden`, id).
        $renglones = [];
        $sinPrograma = [];
        foreach ($bodegas as $bodegaId => $bodega) {
            if (isset($conPlan[$bodegaId])) {
                $renglones[] = $fila($bodegaId, true);
            } elseif (isset($real[$bodegaId])) {
                $sinPrograma[] = $fila($bodegaId, false);
            }
        }

        $suma = fn (array $lista, string $campo): int => array_sum(array_map(fn ($f) => array_sum($f[$campo]), $lista));
        $todas = array_merge($renglones, $sinPrograma);

        return [
            'lunes' => $inicio->toDateString(),
            'hoy' => $semana['hoy'],
            'dias_pasados' => $semana['dias_pasados'],
            'trabaja_domingo' => $trabajaDomingo,
            'renglones' => $renglones,
            'sin_programa' => $sinPrograma,
            'sin_destino' => $sinDestino,
            'plantas' => PlantasAvance::conNombre($plantaIds),
            'totales' => [
                'plan' => $suma($renglones, 'plan'),
                'real' => $suma($todas, 'real') + array_sum($sinDestino['cargas']),
                'atraso' => array_sum(array_column($renglones, 'atraso')),
                'para_hoy' => $hoyEnSemana ? array_sum(array_column($renglones, 'para_hoy')) : null,
                'cajas' => $suma($todas, 'cajas') + array_sum($sinDestino['cajas']),
            ],
        ];
    }
}
