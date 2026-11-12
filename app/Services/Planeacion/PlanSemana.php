<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaRendimiento;
use App\Models\Planeacion\ProgramaSemanal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
     */
    public function __construct(
        public readonly ProgramaSemanal $programa,
        public readonly string $lunes,
        public readonly Collection $cortes,
        public readonly Collection $necesidades,
        public readonly array $elecciones,
        public readonly array $inventariosManuales,
        private readonly CatalogoPlaneacion $catalogo,
    ) {}

    /**
     * Carga varias semanas a la vez (la cadena del arrastre de inventario): el
     * número de consultas no crece con el número de semanas.
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
            'necesidades' => fn ($q) => $q->orderBy('id'),
            'necesidades.variedades:id',
            'rendimientos',
            'inventariosIniciales',
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
            );
        }

        return $planes;
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

    public function grupoDeNecesidad(ProgramaNecesidad $necesidad): ?string
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
     * Renglones de necesidad para CalculadoraBalance (uno por día con cajas).
     *
     * @param  list<array{grupo: ?string, cajas: int[]}>  $necesidades  Salida de necesidades().
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

    /** @return int[] Cultivos que citan los renglones de la semana. */
    public function cultivosCitados(): array
    {
        $ids = $this->necesidades->pluck('cultivo_id')->all();
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
