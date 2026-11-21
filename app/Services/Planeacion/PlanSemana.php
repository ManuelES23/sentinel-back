<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaRendimiento;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaSobrante;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Renglones de una semana ya cargados (con sus relaciones, sin N+1) y el plan
 * que sale de ellos: producción y necesidad por grupo y día. Es la base común
 * del armado de la semana y del arrastre de inventario entre semanas.
 */
class PlanSemana
{
    /**
     * @param  Collection<int, ProgramaCorte>  $cortes
     * @param  Collection<int, ProgramaNecesidad>  $necesidades
     * @param  array<string, ProgramaRendimiento>  $elecciones  "temporada-variedad" => elección.
     * @param  array<string, int>  $inventariosManuales  grupo_clave => cajas.
     * @param  Collection<int, ProgramaSobrante>  $sobrantes  Sobrante asignado (Programa de empaque).
     * @param  Collection<int, ProgramaNecesidad>  $necesidadesSiguiente  Necesidades del programa de la
     *                                                                     semana siguiente (vacía si no existe).
     */
    public function __construct(
        public readonly ProgramaSemanal $programa,
        public readonly string $lunes,
        public readonly Collection $cortes,
        public readonly Collection $necesidades,
        public readonly array $elecciones,
        public readonly array $inventariosManuales,
        private readonly CatalogoPlaneacion $catalogo,
        public readonly Collection $sobrantes = new Collection,
        public readonly Collection $necesidadesSiguiente = new Collection,
    ) {}

    /**
     * Carga varias semanas a la vez (la cadena del arrastre de inventario): el
     * número de consultas no crece con el número de semanas. También trae las
     * necesidades de la semana siguiente de cada una (las que se empacan antes
     * de su lunes): si ese programa ya está en la lista se reusa; si no, se
     * leen todos los que faltan en una sola consulta.
     *
     * @param  EloquentCollection<int, ProgramaSemanal>  $programas
     * @return array<int, self> programa_id => plan
     */
    public static function cargarVarios(EloquentCollection $programas, CatalogoPlaneacion $catalogo): array
    {
        if ($programas->isEmpty()) {
            return [];
        }

        $programas->load([
            'cortes' => fn ($q) => $q->orderBy('dia')->orderBy('id'),
            'cortes.etapa:id,lote_id,nombre,codigo',
            'cortes.etapa.lote:id,nombre',
            'sobrantes' => fn ($q) => $q->orderBy('id'),
            'sobrantes.variedades:id',
            'rendimientos',
            'inventariosIniciales',
        ]);

        // Programas de la semana siguiente que no vienen en la lista (siempre una consulta,
        // aunque no falte ninguno, para que el número de consultas no dependa de los datos).
        $porLunes = [];
        foreach ($programas as $programa) {
            $porLunes[$programa->lunes->toDateString()] = $programa;
        }
        $faltan = [];
        foreach ($porLunes as $lunes => $programa) {
            $siguiente = self::lunesSiguiente($lunes);
            if (! isset($porLunes[$siguiente])) {
                $faltan[$siguiente] = true;
            }
        }
        $fechas = $faltan === [] ? array_keys($porLunes) : array_keys($faltan);
        $extras = ProgramaSemanal::whereDate('lunes', '>=', min($fechas))
            ->whereDate('lunes', '<=', max($fechas))
            ->whereNotIn('id', $programas->modelKeys())
            ->get()
            ->filter(fn (ProgramaSemanal $p) => isset($faltan[$p->lunes->toDateString()]));
        foreach ($extras as $programa) {
            $porLunes[$programa->lunes->toDateString()] = $programa;
        }

        // Las necesidades de todos (los de la lista y los siguientes) van juntas.
        (new EloquentCollection([...$programas->all(), ...$extras->all()]))->load([
            'necesidades' => fn ($q) => $q->orderBy('id'),
            'necesidades.variedades:id',
        ]);

        $planes = [];
        foreach ($programas as $programa) {
            $elecciones = [];
            foreach ($programa->rendimientos as $r) {
                $elecciones[$r->temporada_id.'-'.$r->variedad_id] = $r;
            }

            $manuales = [];
            foreach ($programa->inventariosIniciales as $i) {
                $manuales[(string) $i->grupo_clave] = (int) $i->cajas;
            }

            $planes[$programa->id] = new self(
                $programa,
                $programa->lunes->toDateString(),
                $programa->cortes,
                $programa->necesidades,
                $elecciones,
                $manuales,
                $catalogo,
                $programa->sobrantes,
                ($porLunes[self::lunesSiguiente($programa->lunes->toDateString())] ?? null)?->necesidades ?? new Collection,
            );
        }

        return $planes;
    }

    private static function lunesSiguiente(string $lunes): string
    {
        return Carbon::parse($lunes)->addDays(CalculadoraBalance::DIAS)->toDateString();
    }

    public function eleccion(?int $temporadaId, int $variedadId): ?ProgramaRendimiento
    {
        return $this->elecciones[$temporadaId.'-'.$variedadId] ?? null;
    }

    public function valorUsado(?int $temporadaId, int $variedadId): ?float
    {
        return $this->catalogo->valorUsado($temporadaId, $variedadId, $this->eleccion($temporadaId, $variedadId));
    }

    /** Cajas estimadas de un corte: ha × rendimiento elegido, redondeadas; sin rendimiento, 0. */
    public function cajasDeCorte(ProgramaCorte $corte): int
    {
        $valor = $this->valorUsado($corte->temporada_id, $corte->variedad_id);

        return $valor === null ? 0 : (int) round((float) $corte->hectareas * $valor);
    }

    /** Grupo de balance de un renglón de venta o de sobrante: el de su primera variedad. */
    public function grupoDeNecesidad(ProgramaNecesidad|ProgramaSobrante $necesidad): ?string
    {
        $variedad = $necesidad->variedades->first();

        return $variedad ? $this->catalogo->grupoDeVariedad($variedad->id) : null;
    }

    /**
     * Renglones de producción para CalculadoraBalance: uno por corte.
     *
     * @return list<array{grupo: string, temporada_id: ?int, dia: int, cajas: int}>
     */
    public function produccion(): array
    {
        $renglones = [];
        foreach ($this->cortes as $corte) {
            if ($this->diaValido($corte->dia)) {
                $renglones[] = [
                    'grupo' => $this->catalogo->grupoDeVariedad($corte->variedad_id),
                    'temporada_id' => $corte->temporada_id,
                    'dia' => $corte->dia,
                    'cajas' => $this->cajasDeCorte($corte),
                ];
            }
        }

        return $renglones;
    }

    /**
     * Necesidades de la semana con su grupo de balance y las cajas de cada día.
     *
     * @return list<array{id: int, necesidad: ProgramaNecesidad, grupo: ?string, cajas: int[]}>
     */
    public function necesidades(): array
    {
        $renglones = [];
        foreach ($this->necesidades as $necesidad) {
            $renglones[] = [
                'id' => $necesidad->id,
                'necesidad' => $necesidad,
                'grupo' => $this->grupoDeNecesidad($necesidad),
                'cajas' => $necesidad->cajasPorDia(),
            ];
        }

        return $renglones;
    }

    /**
     * Necesidades por DÍA DE EMPAQUE (llegada − días de antelación del destino):
     * las de esta semana que se empacan dentro de ella, más las de la semana
     * siguiente que se empacan antes de su lunes (caen al final de esta). Lo que
     * de esta semana se empaca antes de su lunes ya no cuenta aquí: le toca a la
     * semana anterior. Los renglones que quedan sin cajas se omiten.
     *
     * @return list<array{id: int, necesidad: ProgramaNecesidad, grupo: ?string, cajas: int[], de_semana_siguiente: bool}>
     */
    public function necesidadesPorEmpaque(): array
    {
        $renglones = [];
        foreach ([[false, $this->necesidades], [true, $this->necesidadesSiguiente]] as [$deSiguiente, $necesidades]) {
            foreach ($necesidades as $necesidad) {
                $antelacion = $this->catalogo->antelacionDe((int) $necesidad->bodega_id);
                $cajas = array_fill(0, CalculadoraBalance::DIAS, 0);
                foreach ($necesidad->cajasPorDia() as $llegada => $cantidad) {
                    // Día de empaque contado desde el lunes de esta semana.
                    $empaque = $llegada - $antelacion + ($deSiguiente ? CalculadoraBalance::DIAS : 0);
                    if ($this->diaValido($empaque)) {
                        $cajas[$empaque] += $cantidad;
                    }
                }
                if (array_filter($cajas) === []) {
                    continue;
                }
                $renglones[] = [
                    'id' => $necesidad->id,
                    'necesidad' => $necesidad,
                    'grupo' => $this->grupoDeNecesidad($necesidad),
                    'cajas' => $cajas,
                    'de_semana_siguiente' => $deSiguiente,
                ];
            }
        }

        return $renglones;
    }

    /**
     * Sobrante asignado de la semana con su grupo de balance y las cajas de
     * cada día. Va siempre aparte de las necesidades, que son solo ventas.
     *
     * @return list<array{id: int, sobrante: ProgramaSobrante, grupo: ?string, cajas: int[]}>
     */
    public function sobrantes(): array
    {
        $renglones = [];
        foreach ($this->sobrantes as $sobrante) {
            $renglones[] = [
                'id' => $sobrante->id,
                'sobrante' => $sobrante,
                'grupo' => $this->grupoDeNecesidad($sobrante),
                'cajas' => $sobrante->cajasPorDia(),
            ];
        }

        return $renglones;
    }

    /**
     * Renglones de necesidad (o de sobrante) para CalculadoraBalance (uno por día con cajas).
     *
     * @param  list<array{grupo: ?string, cajas: int[]}>  $necesidades  Salida de necesidades() o de sobrantes().
     * @return list<array{grupo: string, dia: int, cajas: int}>
     */
    public static function renglonesNecesidad(array $necesidades): array
    {
        $renglones = [];
        foreach ($necesidades as $n) {
            if ($n['grupo'] === null) {
                continue;
            }
            foreach ($n['cajas'] as $dia => $cajas) {
                if ($cajas !== 0) {
                    $renglones[] = ['grupo' => $n['grupo'], 'dia' => $dia, 'cajas' => $cajas];
                }
            }
        }

        return $renglones;
    }

    /** @return int[] Cultivos que citan los renglones de la semana (y los de la siguiente que se empacan en ella). */
    public function cultivosCitados(): array
    {
        $ids = array_merge(
            $this->necesidades->pluck('cultivo_id')->all(),
            $this->sobrantes->pluck('cultivo_id')->all(),
        );
        if ($this->necesidadesSiguiente->isNotEmpty()) {
            foreach ($this->necesidadesPorEmpaque() as $renglon) {
                if ($renglon['de_semana_siguiente']) {
                    $ids[] = $renglon['necesidad']->cultivo_id;
                }
            }
        }
        foreach ($this->cortes as $corte) {
            $variedad = $this->catalogo->variedades()->get($corte->variedad_id);
            if ($variedad) {
                $ids[] = (int) $variedad->cultivo_id;
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function diaValido(?int $dia): bool
    {
        return $dia !== null && $dia >= 0 && $dia < CalculadoraBalance::DIAS;
    }
}
