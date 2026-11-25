<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaPlanAcordado;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Temporada;
use Illuminate\Validation\ValidationException;

/**
 * Plan acordado en la junta de la semana: una foto del plan de corte y de las
 * cargas de embarque. Puede acordarse varias veces; vale la foto de mayor `id`.
 * Acordar no bloquea la edición del plan: las pantallas de avance muestran el
 * plan vigente y marcan, celda por celda, lo que difiere de la foto.
 *
 * Marcas: una celda lleva `acordado` (el valor de la foto) solo si difiere del
 * plan vigente (cosecha comparada a 2 decimales; embarques si Full o Sencillo
 * difieren). Un valor que vuelve a lo acordado deja de marcarse; una llave que
 * no está en la foto cuenta como 0 acordado, y una que está en la foto pero ya
 * no en el plan, como 0 vigente («quitado»).
 */
class PlanAcordado
{
    public const MENSAJE_SIN_PLAN = 'La semana no tiene plan de corte ni de embarques que acordar.';

    /** La foto vigente (mayor `id`) del programa, con quien la acordó; null si no hay. */
    public function vigente(int $programaId): ?ProgramaPlanAcordado
    {
        return ProgramaPlanAcordado::where('programa_id', $programaId)
            ->with('acordadoPor:id,name')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Quién y cuándo acordó la foto vigente, en una sola consulta (para el
     * payload del programa); null si la semana no tiene foto.
     *
     * @return array{por: ?array{id: int, nombre: string}, en: string}|null
     */
    public function resumen(int $programaId): ?array
    {
        $fila = ProgramaPlanAcordado::query()
            ->leftJoin('users', 'users.id', '=', 'programa_planes_acordados.acordado_por')
            ->where('programa_planes_acordados.programa_id', $programaId)
            ->orderByDesc('programa_planes_acordados.id')
            ->first(['programa_planes_acordados.id', 'programa_planes_acordados.acordado_por', 'programa_planes_acordados.created_at', 'users.name as acordado_por_nombre']);

        if ($fila === null) {
            return null;
        }

        return [
            'por' => $fila->acordado_por === null || $fila->acordado_por_nombre === null
                ? null
                : ['id' => (int) $fila->acordado_por, 'nombre' => (string) $fila->acordado_por_nombre],
            'en' => $fila->created_at?->toIso8601String(),
        ];
    }

    /**
     * Guarda la foto del plan del programa. Debe llamarse dentro de una
     * transacción con la fila del programa ya bloqueada. 422 en `plan` si la
     * semana no tiene cortes ni embarques.
     */
    public function acordar(ProgramaSemanal $programaBloqueado, ?int $userId): ProgramaPlanAcordado
    {
        $cortes = ProgramaCorte::where('programa_id', $programaBloqueado->id)
            ->orderBy('id')
            ->get(['temporada_id', 'etapa_id', 'variedad_id', 'dia', 'hectareas'])
            ->map(fn (ProgramaCorte $c) => [
                'temporada_id' => $c->temporada_id === null ? null : (int) $c->temporada_id,
                'etapa_id' => $c->etapa_id === null ? null : (int) $c->etapa_id,
                'variedad_id' => $c->variedad_id === null ? null : (int) $c->variedad_id,
                'dia' => (int) $c->dia,
                'hectareas' => round((float) $c->hectareas, 2),
            ])->all();

        $embarques = ProgramaEmbarque::where('programa_id', $programaBloqueado->id)
            ->orderBy('id')
            ->get(['cultivo_id', 'bodega_id', 'tipo', 'dia', 'cargas'])
            ->map(fn (ProgramaEmbarque $e) => [
                'cultivo_id' => $e->cultivo_id === null ? null : (int) $e->cultivo_id,
                'bodega_id' => (int) $e->bodega_id,
                'tipo' => (string) $e->tipo,
                'dia' => (int) $e->dia,
                'cargas' => (int) $e->cargas,
            ])->all();

        if ($cortes === [] && $embarques === []) {
            throw ValidationException::withMessages(['plan' => self::MENSAJE_SIN_PLAN]);
        }

        $foto = ProgramaPlanAcordado::create([
            'programa_id' => $programaBloqueado->id,
            'acordado_por' => $userId,
            'cortes' => $cortes,
            'embarques' => $embarques,
        ]);

        return $foto->load('acordadoPor:id,name');
    }

    /**
     * Hectáreas de la foto por llave del renglón (`e{etapa_id}` o, sin etapa,
     * `v{variedad_id}`) y día, sumando filas y respetando el filtro de
     * temporada y de cultivo (el de la temporada de cada fila).
     *
     * @return array<string, float[]>
     */
    public function cortesPorLlave(?ProgramaPlanAcordado $foto, ?int $temporadaId, ?int $cultivoId): array
    {
        if ($foto === null) {
            return [];
        }

        $permitidas = null;
        if ($temporadaId !== null || $cultivoId !== null) {
            $permitidas = array_flip(Temporada::withTrashed()
                ->when($temporadaId !== null, fn ($q) => $q->whereKey($temporadaId))
                ->when($cultivoId !== null, fn ($q) => $q->where('cultivo_id', $cultivoId))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        $dias = CalculadoraBalance::DIAS;
        $ceros = array_fill(0, $dias, 0.0);
        $salida = [];
        foreach ($foto->cortes ?? [] as $c) {
            $dia = (int) ($c['dia'] ?? -1);
            if ($dia < 0 || $dia >= $dias) {
                continue;
            }
            if ($permitidas !== null && ! isset($permitidas[(int) ($c['temporada_id'] ?? 0)])) {
                continue;
            }
            $llave = self::llaveCorte($c['etapa_id'] ?? null, $c['variedad_id'] ?? null);
            $salida[$llave] ??= $ceros;
            $salida[$llave][$dia] += (float) ($c['hectareas'] ?? 0);
        }

        return array_map(fn (array $serie) => array_map(fn ($v) => round($v, 2), $serie), $salida);
    }

    /**
     * Cargas de la foto por bodega, tipo y día, filtradas por cultivo.
     *
     * @return array<int, array{full: int[], sencillo: int[]}>
     */
    public function embarquesPorBodega(?ProgramaPlanAcordado $foto, ?int $cultivoId): array
    {
        if ($foto === null) {
            return [];
        }

        $dias = CalculadoraBalance::DIAS;
        $ceros = array_fill(0, $dias, 0);
        $salida = [];
        foreach ($foto->embarques ?? [] as $e) {
            $dia = (int) ($e['dia'] ?? -1);
            if ($dia < 0 || $dia >= $dias) {
                continue;
            }
            if ($cultivoId !== null && (int) ($e['cultivo_id'] ?? 0) !== $cultivoId) {
                continue;
            }
            $bodegaId = (int) $e['bodega_id'];
            $tipo = ($e['tipo'] ?? '') === 'sencillo' ? 'sencillo' : 'full';
            $salida[$bodegaId] ??= ['full' => $ceros, 'sencillo' => $ceros];
            $salida[$bodegaId][$tipo][$dia] += (int) ($e['cargas'] ?? 0);
        }

        return $salida;
    }

    /** Llave de un renglón de corte: `e{etapa_id}` o, sin etapa, `v{variedad_id}`. */
    public static function llaveCorte(int|string|null $etapaId, int|string|null $variedadId): string
    {
        return $etapaId !== null ? 'e'.(int) $etapaId : 'v'.(int) $variedadId;
    }

    /**
     * Marcas de un renglón de cosecha: por día, el valor acordado si difiere
     * del vigente (a 2 decimales); null si es igual o si no hay foto.
     *
     * @param  float[]|null  $acordado  Serie de la foto (null = la llave no estaba: 0 acordado).
     * @param  float[]  $plan  Serie vigente, ya redondeada.
     * @return array<int, ?float>
     */
    public function marcasCosecha(bool $hayFoto, ?array $acordado, array $plan): array
    {
        $marcas = [];
        foreach ($plan as $dia => $vigente) {
            $antes = round((float) ($acordado[$dia] ?? 0), 2);
            $marcas[$dia] = $hayFoto && $antes !== round((float) $vigente, 2) ? $antes : null;
        }

        return $marcas;
    }

    /**
     * Marcas de un renglón de embarques: por día, `{full, sencillo}` de la foto
     * si alguno difiere del vigente; null si son iguales o si no hay foto.
     *
     * @param  array{full: int[], sencillo: int[]}|null  $acordado
     * @param  int[]  $full
     * @param  int[]  $sencillo
     * @return array<int, ?array{full: int, sencillo: int}>
     */
    public function marcasEmbarques(bool $hayFoto, ?array $acordado, array $full, array $sencillo): array
    {
        $marcas = [];
        foreach ($full as $dia => $vigenteFull) {
            $antesFull = (int) ($acordado['full'][$dia] ?? 0);
            $antesSencillo = (int) ($acordado['sencillo'][$dia] ?? 0);
            $difiere = $antesFull !== (int) $vigenteFull || $antesSencillo !== (int) ($sencillo[$dia] ?? 0);
            $marcas[$dia] = $hayFoto && $difiere ? ['full' => $antesFull, 'sencillo' => $antesSencillo] : null;
        }

        return $marcas;
    }

    /**
     * Bloque raíz `acordado` de las respuestas de avance. `cambios` se ordena
     * por día, nombre y tipo.
     *
     * @param  list<array>  $cambios
     * @return array{existe: bool, por: ?array{id: int, nombre: string}, en: ?string, cambios: list<array>}
     */
    public function bloque(?ProgramaPlanAcordado $foto, array $cambios): array
    {
        usort($cambios, fn (array $a, array $b) => [$a['dia'], mb_strtolower((string) $a['nombre']), $a['tipo'] ?? '']
            <=> [$b['dia'], mb_strtolower((string) $b['nombre']), $b['tipo'] ?? '']);

        return [
            'existe' => $foto !== null,
            'por' => $foto?->acordadoPor ? ['id' => (int) $foto->acordadoPor->id, 'nombre' => (string) $foto->acordadoPor->name] : null,
            'en' => $foto?->created_at?->toIso8601String(),
            'cambios' => $foto === null ? [] : array_values($cambios),
        ];
    }
}
