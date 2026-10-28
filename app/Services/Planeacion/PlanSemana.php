<?php

namespace App\Services\Planeacion;

use App\Models\Planeacion\ProgramaAcuerdo;
use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaRendimiento;
use App\Models\Planeacion\ProgramaSemanal;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Renglones de una semana ya cargados (con sus relaciones, sin N+1) y el plan
 * efectivo que sale de ellos: producción y necesidad por grupo y día, con o
 * sin los acuerdos de la junta. Es la base común del armado de la semana y
 * del arrastre de inventario entre semanas.
 */
class PlanSemana
{
    /**
     * @param  Collection<int, ProgramaCorte>  $cortes
     * @param  Collection<int, ProgramaNecesidad>  $necesidades
     * @param  Collection<int, ProgramaAcuerdo>  $acuerdos  Todos los de la semana (activos e inactivos).
     * @param  Collection<int, ProgramaAcuerdo>  $entrantes  «Mover pedido» activos de otras semanas con destino a esta.
     * @param  array<string, ProgramaRendimiento>  $elecciones  "temporada-variedad" => elección.
     * @param  array<string, int>  $inventariosManuales  grupo_clave => cajas.
     */
    public function __construct(
        public readonly ProgramaSemanal $programa,
        public readonly string $lunes,
        public readonly Collection $cortes,
        public readonly Collection $necesidades,
        public readonly Collection $acuerdos,
        public readonly Collection $entrantes,
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
            'acuerdos' => fn ($q) => $q->orderBy('id'),
            'acuerdos.creador:id,name',
            'rendimientos',
            'inventariosIniciales',
        ]);

        // «Mover pedido» activos hacia cualquiera de estas semanas, desde otra semana.
        $lunes = $programas->map(fn (ProgramaSemanal $p) => $p->lunes->toDateString());
        $entrantesPorLunes = ProgramaAcuerdo::with(['necesidad.variedades:id', 'programa:id,lunes'])
            ->where('tipo', 'mover_pedido')
            ->where('activo', true)
            ->whereDate('destino_lunes', '>=', $lunes->min())
            ->whereDate('destino_lunes', '<=', $lunes->max())
            ->orderBy('id')
            ->get()
            ->filter(fn (ProgramaAcuerdo $a) => $a->necesidad !== null && $a->programa !== null)
            ->groupBy(fn (ProgramaAcuerdo $a) => $a->destino_lunes->toDateString());

        $planes = [];
        foreach ($programas as $programa) {
            $fecha = $programa->lunes->toDateString();

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
                $fecha,
                $programa->cortes,
                $programa->necesidades,
                $programa->acuerdos,
                $entrantesPorLunes->get($fecha, collect())
                    ->filter(fn (ProgramaAcuerdo $a) => (int) $a->programa_id !== (int) $programa->id)
                    ->values(),
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

    /**
     * Si un acuerdo de producción (adelantar etapa o conseguir producto) puede
     * sumar cajas: necesita variedad (para saber su grupo) y un día de la semana.
     */
    public function sumaProduccion(ProgramaAcuerdo $acuerdo): bool
    {
        return in_array($acuerdo->tipo, ['adelantar_etapa', 'conseguir_producto'], true)
            && $acuerdo->variedad_id
            && $this->diaValido($acuerdo->dia);
    }

    /**
     * Cajas que suma un acuerdo de producción (adelantar etapa o conseguir
     * producto), activo o no; 0 si no puede contar (sin variedad o sin día).
     */
    public function cajasDeAcuerdo(ProgramaAcuerdo $acuerdo): int
    {
        if (! $this->sumaProduccion($acuerdo)) {
            return 0;
        }

        if ($acuerdo->tipo === 'conseguir_producto') {
            return (int) $acuerdo->cajas;
        }

        $valor = $this->valorUsado($acuerdo->temporada_id, $acuerdo->variedad_id);

        return $valor === null ? 0 : (int) round((float) $acuerdo->hectareas * $valor);
    }

    /** Cajas que un «mover pedido» saca de su renglón: nunca más de las que tiene ese día. */
    public static function cajasMovidas(ProgramaAcuerdo $acuerdo, ProgramaNecesidad $necesidad): int
    {
        $dia = $acuerdo->dia_origen;
        if ($dia === null || $dia < 0 || $dia > 5) {
            return 0;
        }

        return max(0, min((int) $acuerdo->cajas, (int) $necesidad->{"cajas_{$dia}"}));
    }

    public function grupoDeNecesidad(ProgramaNecesidad $necesidad): ?string
    {
        $variedad = $necesidad->variedades->first();

        return $variedad ? $this->catalogo->grupoDeVariedad($variedad->id) : null;
    }

    /** @return Collection<int, ProgramaAcuerdo> */
    public function acuerdosActivos(string $tipo): Collection
    {
        return $this->acuerdos->filter(fn (ProgramaAcuerdo $a) => $a->activo && $a->tipo === $tipo)->values();
    }

    /**
     * Renglones de producción para CalculadoraBalance: el corte y, con
     * acuerdos, las etapas adelantadas y el producto conseguido.
     *
     * @return list<array{grupo: string, temporada_id: ?int, dia: int, cajas: int}>
     */
    public function produccion(bool $conAcuerdos): array
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

        if ($conAcuerdos) {
            foreach ($this->acuerdos as $acuerdo) {
                if ($acuerdo->activo && $this->sumaProduccion($acuerdo)) {
                    $renglones[] = [
                        'grupo' => $this->catalogo->grupoDeVariedad($acuerdo->variedad_id),
                        'temporada_id' => $acuerdo->temporada_id,
                        'dia' => $acuerdo->dia,
                        'cajas' => $this->cajasDeAcuerdo($acuerdo),
                    ];
                }
            }
        }

        return $renglones;
    }

    /**
     * Necesidades de la semana con el efecto de los «mover pedido»: los propios
     * restan en su día de origen (y suman en el destino si es esta semana) y
     * los de otras semanas con destino a esta aparecen como renglón movido.
     *
     * @return list<array{id: int, necesidad: ProgramaNecesidad, grupo: ?string, base: int[], cajas: int[], movida_desde: ?array}>
     */
    public function necesidades(bool $conAcuerdos): array
    {
        $movimientos = $conAcuerdos ? $this->acuerdosActivos('mover_pedido')->groupBy('necesidad_id') : collect();

        $renglones = [];
        foreach ($this->necesidades as $necesidad) {
            $base = $necesidad->cajasPorDia();
            $cajas = $base;

            foreach ($movimientos->get($necesidad->id, []) as $acuerdo) {
                if (! $this->diaValido($acuerdo->dia_origen)) {
                    continue;
                }

                $movidas = self::cajasMovidas($acuerdo, $necesidad);
                $cajas[$acuerdo->dia_origen] = max(0, $cajas[$acuerdo->dia_origen] - $movidas);

                $mismaSemana = $acuerdo->destino_lunes === null || $acuerdo->destino_lunes->toDateString() === $this->lunes;
                if ($mismaSemana && $this->diaValido($acuerdo->dia_destino)) {
                    $cajas[$acuerdo->dia_destino] += $movidas;
                }
            }

            $renglones[] = [
                'id' => $necesidad->id,
                'necesidad' => $necesidad,
                'grupo' => $this->grupoDeNecesidad($necesidad),
                'base' => $base,
                'cajas' => $cajas,
                'movida_desde' => null,
            ];
        }

        if ($conAcuerdos) {
            foreach ($this->entrantes as $acuerdo) {
                if (! $this->diaValido($acuerdo->dia_destino)) {
                    continue;
                }

                $cajas = array_fill(0, CalculadoraBalance::DIAS, 0);
                $cajas[$acuerdo->dia_destino] = self::cajasMovidas($acuerdo, $acuerdo->necesidad);

                $renglones[] = [
                    'id' => -$acuerdo->id,
                    'necesidad' => $acuerdo->necesidad,
                    'grupo' => $this->grupoDeNecesidad($acuerdo->necesidad),
                    'base' => $cajas,
                    'cajas' => $cajas,
                    'movida_desde' => [
                        'lunes' => $acuerdo->programa->lunes->toDateString(),
                        'acuerdo_id' => $acuerdo->id,
                    ],
                ];
            }
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
        foreach ($this->entrantes as $acuerdo) {
            $ids[] = (int) $acuerdo->necesidad->cultivo_id;
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function diaValido(?int $dia): bool
    {
        return $dia !== null && $dia >= 0 && $dia < CalculadoraBalance::DIAS;
    }
}
