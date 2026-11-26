<?php

namespace App\Services\Planeacion;

use App\Models\Consignatario;
use App\Models\DestinoEmbarque;
use App\Models\EmbarqueEmpaque;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Temporada;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

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
 * Destino real: si el embarque trae destino del catálogo (`destino_embarque_id`)
 * ligado con una bodega de Planeación, esa bodega (aunque el nombre no coincida).
 * Si no, se compara por `ClientesPlaneacion::clave()` contra las bodegas de
 * Planeación (todas, activas o no) el nombre del destino del catálogo; sin
 * catálogo, la bodega del consignatario de destino si la tiene (no vacía) y si
 * no, el texto `destino`. Con plan (vigente o
 * acordado) va a `renglones`; reconocido sin plan, a `sin_programa`; sin
 * reconocer (o sin destino), a `sin_destino`, agrupado por destino.
 *
 * Plan acordado (ver `PlanAcordado`): los renglones son las bodegas del plan
 * vigente ∪ las de la foto vigente (una bodega solo de la foto sale con el plan
 * en ceros: «quitada»). Cada fila lleva `acordado[7]` (`{full, sencillo}` de la
 * foto solo donde alguno difiere) y la raíz el bloque `acordado` con los
 * `cambios` por destino, tipo y día. La foto también se filtra por cultivo.
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

    public function __construct(
        private PlantasAvance $plantas = new PlantasAvance,
        private PlanAcordado $planes = new PlanAcordado,
    ) {}

    public function de(string $lunes, ?int $cultivoId, CarbonInterface $hoy): array
    {
        $ceros = array_fill(0, CalculadoraBalance::DIAS, 0);

        $inicio = CarbonImmutable::parse($lunes)->startOfDay();
        $semana = AtrasoSemana::dias($lunes, $hoy);

        $programa = ProgramaSemanal::whereDate('lunes', $lunes)->first(['id', 'domingo_embarques']);
        $trabajaDomingo = $programa ? $programa->trabajaDomingo('embarques') : true;

        [$full, $sencillo, $conPlan] = $this->plan($programa, $cultivoId);

        $foto = $programa ? $this->planes->vigente($programa->id) : null;
        $hayFoto = $foto !== null;
        $acordado = $this->planes->embarquesPorBodega($foto, $cultivoId);

        // Bodegas de Planeación (todas) y su mapa por clave de nombre.
        $bodegas = BodegaDestino::orderBy('orden')->orderBy('id')->get(['id', 'nombre'])->keyBy('id');
        $plantaIds = $this->plantas->ids();
        [$real, $cajas, $sinDestino] = $this->real($inicio, $cultivoId, $plantaIds, $bodegas);

        $fila = function (int $bodegaId, bool $programada) use ($bodegas, $full, $sencillo, $real, $cajas, $ceros, $semana, $acordado, $hayFoto): array {
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
                'acordado' => $this->planes->marcasEmbarques($hayFoto, $acordado[$bodegaId] ?? null, $fullFila, $sencilloFila),
                'real' => $realFila,
                'cajas' => $cajas[$bodegaId] ?? $ceros,
                'atraso' => $atraso,
                'para_hoy' => $programada && $semana['hoy_en_semana'] ? $planFila[$semana['indice_hoy']] + $atraso : null,
            ];
        };

        // Filas en el orden de las bodegas (`orden`, id); renglones = plan vigente ∪ foto.
        $renglones = [];
        $sinPrograma = [];
        foreach ($bodegas as $bodegaId => $bodega) {
            if (isset($conPlan[$bodegaId]) || isset($acordado[$bodegaId])) {
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
                'para_hoy' => $semana['hoy_en_semana'] ? array_sum(array_column($renglones, 'para_hoy')) : null,
                'cajas' => $suma($todas, 'cajas') + array_sum($sinDestino['cajas']),
            ],
            'acordado' => $this->planes->bloque($foto, $this->cambios($renglones)),
        ];
    }

    /**
     * Cargas programadas por bodega, tipo y día, y las bodegas con plan.
     *
     * @return array{0: array<int, int[]>, 1: array<int, int[]>, 2: array<int, true>}
     */
    private function plan(?ProgramaSemanal $programa, ?int $cultivoId): array
    {
        $dias = CalculadoraBalance::DIAS;
        $ceros = array_fill(0, $dias, 0);
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

        return [$full, $sencillo, $conPlan];
    }

    /**
     * Real de la semana: una carga por embarque salido, a su bodega o a «sin
     * destino» (agrupado por destino).
     *
     * @param  list<int>  $plantaIds
     * @return array{0: array<int, int[]>, 1: array<int, int[]>, 2: array{cargas: int[], cajas: int[], destinos: list<array{destino: string, cargas: int}>}}
     */
    private function real(CarbonImmutable $inicio, ?int $cultivoId, array $plantaIds, Collection $bodegas): array
    {
        $dias = CalculadoraBalance::DIAS;
        $ceros = array_fill(0, $dias, 0);

        $porClave = [];
        foreach ($bodegas as $bodega) {
            $clave = ClientesPlaneacion::clave((string) $bodega->nombre);
            if ($clave !== '') {
                $porClave[$clave] ??= (int) $bodega->id;
            }
        }

        $embarques = $plantaIds === []
            ? collect()
            : EmbarqueEmpaque::query()
                ->where('fecha_embarque', '>=', $inicio->toDateString())
                ->where('fecha_embarque', '<', $inicio->addDays($dias)->toDateString())
                ->whereIn('status', self::ESTADOS_REALES)
                ->whereIn('entity_id', $plantaIds)
                ->when($cultivoId !== null, fn ($q) => $q->whereIn(
                    'temporada_id',
                    Temporada::withTrashed()->where('cultivo_id', $cultivoId)->select('id'),
                ))
                ->orderBy('id')
                ->get(['id', 'fecha_embarque', 'destino', 'destino_consignatario_id', 'destino_embarque_id', 'total_cajas']);

        $destinoIds = $embarques->pluck('destino_embarque_id')->filter()->unique()->values()->all();
        $destinosCatalogo = $destinoIds
            ? DestinoEmbarque::whereIn('id', $destinoIds)->get(['id', 'nombre', 'plan_bodega_destino_id'])->keyBy('id')
            : collect();

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

            $catalogo = $e->destino_embarque_id ? $destinosCatalogo->get($e->destino_embarque_id) : null;
            if ($catalogo) {
                $texto = trim((string) $catalogo->nombre);
            } else {
                $bodegaConsignatario = trim((string) $bodegaDeConsignatario->get($e->destino_consignatario_id, ''));
                $texto = trim($bodegaConsignatario !== '' ? $bodegaConsignatario : (string) $e->destino);
            }
            $clave = ClientesPlaneacion::clave($texto);
            $ligada = $catalogo?->plan_bodega_destino_id;
            $bodegaId = $ligada !== null && $bodegas->has($ligada) ? (int) $ligada : ($porClave[$clave] ?? null);

            if ($bodegaId !== null) {
                $real[$bodegaId] ??= $ceros;
                $cajas[$bodegaId] ??= $ceros;
                $real[$bodegaId][$dia]++;
                $cajas[$bodegaId][$dia] += $total;

                continue;
            }

            $sinDestino['cargas'][$dia]++;
            $sinDestino['cajas'][$dia] += $total;
            // La clave vacía agrupa todo lo que no trae destino.
            $sinDestino['destinos'][$clave] ??= ['destino' => $clave === '' ? 'Sin destino' : $texto, 'cargas' => 0];
            $sinDestino['destinos'][$clave]['cargas']++;
        }
        $destinos = array_values($sinDestino['destinos']);
        usort($destinos, fn (array $a, array $b) => [$b['cargas'], mb_strtolower($a['destino'])] <=> [$a['cargas'], mb_strtolower($b['destino'])]);
        $sinDestino['destinos'] = $destinos;

        return [$real, $cajas, $sinDestino];
    }

    /**
     * Cambios después de la junta: uno por destino, tipo y día cuyo número de
     * cargas difiere del acordado.
     *
     * @return list<array{dia: int, bodega_id: int, tipo: string, nombre: ?string, antes: int, ahora: int}>
     */
    private function cambios(array $renglones): array
    {
        $cambios = [];
        foreach ($renglones as $f) {
            foreach ($f['acordado'] as $dia => $antes) {
                if ($antes === null) {
                    continue;
                }
                foreach (['full', 'sencillo'] as $tipo) {
                    if ($antes[$tipo] !== $f[$tipo][$dia]) {
                        $cambios[] = [
                            'dia' => $dia,
                            'bodega_id' => $f['bodega_id'],
                            'tipo' => $tipo,
                            'nombre' => $f['nombre'],
                            'antes' => $antes[$tipo],
                            'ahora' => $f[$tipo][$dia],
                        ];
                    }
                }
            }
        }

        return $cambios;
    }
}
