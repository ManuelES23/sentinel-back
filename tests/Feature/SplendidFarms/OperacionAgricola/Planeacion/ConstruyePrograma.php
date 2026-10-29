<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\Planeacion;

use App\Models\Application;
use App\Models\Cultivo;
use App\Models\Enterprise;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\GrupoBalance;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\RendimientoVariedad;
use App\Models\Planeacion\TipoEmpaque;
use App\Models\Productor;
use App\Models\Temporada;
use App\Models\User;
use App\Models\Variedad;
use App\Models\ZonaCultivo;
use Illuminate\Support\Facades\DB;

/**
 * Ayudante de pruebas del Programa semanal: arma el elote con su temporada
 * OI 2026-2027, los catálogos sembrados, usuarios con permisos del submódulo
 * y la semana WK48 tal como viene en el Excel (spec §7).
 */
trait ConstruyePrograma
{
    protected Cultivo $cultivo;

    /** @var array<string, Variedad> nombre => variedad */
    protected array $variedades = [];

    protected Temporada $temporada;

    protected Lote $lote;

    protected User $autor;

    private int $contadorEtapas = 0;

    private function correrMigracion(string $archivo): void
    {
        (require base_path('database/migrations/'.$archivo))->up();
    }

    /**
     * Cultivo «Elote Dulce» con Amarillo, Bicolor y Blanco; temporada OI
     * 2026-2027 abierta del 23 nov 2026 al 5 jun 2027; un lote activo en la
     * temporada; catálogos de elote sembrados y rangos de rendimiento cuyo
     * medio es el del Excel (550, 650 y 700).
     */
    protected function crearElote(): void
    {
        $this->autor = User::factory()->create();
        $this->cultivo = Cultivo::create(['nombre' => 'Elote Dulce']);
        foreach (['Amarillo', 'Bicolor', 'Blanco'] as $nombre) {
            $this->variedades[$nombre] = Variedad::create([
                'cultivo_id' => $this->cultivo->id,
                'nombre' => $nombre,
                'user_id' => $this->autor->id,
            ]);
        }

        $this->temporada = Temporada::create([
            'cultivo_id' => $this->cultivo->id,
            'nombre' => 'OI 2026-2027',
            'locacion' => 'Los Mochis',
            'folio_temporada' => $this->cultivo->id.'-001',
            'año_inicio' => 2026,
            'año_fin' => 2027,
            'fecha_inicio' => '2026-11-23',
            'fecha_fin' => '2027-06-05',
            'estado' => 'abierta',
            'user_id' => $this->autor->id,
        ]);

        $productor = Productor::create([
            'nombre' => 'Juan',
            'apellido' => 'Pérez',
            'tipo' => Productor::TIPO_EXTERNO,
            'is_active' => true,
        ]);
        $zona = ZonaCultivo::create(['nombre' => 'Zona Norte', 'is_active' => true]);
        $this->lote = Lote::create([
            'productor_id' => $productor->id,
            'zona_cultivo_id' => $zona->id,
            'nombre' => 'El Carrizo',
            'superficie' => 500,
            'is_active' => true,
        ]);
        $this->temporada->lotes()->attach($this->lote->id, [
            'cultivo_id' => $this->cultivo->id,
            'is_active' => true,
        ]);

        // La migración de catálogos solo siembra el elote si el cultivo ya existe.
        $this->correrMigracion('2026_10_28_100300_seed_planeacion_catalogos_elote.php');

        foreach (['Amarillo' => [500, 600], 'Bicolor' => [600, 700], 'Blanco' => [650, 750]] as $nombre => [$min, $max]) {
            RendimientoVariedad::create([
                'temporada_id' => $this->temporada->id,
                'variedad_id' => $this->variedades[$nombre]->id,
                'minimo' => $min,
                'maximo' => $max,
            ]);
        }
    }

    protected function crearEtapa(string $variedad, float $superficie, array $extra = []): Etapa
    {
        $this->contadorEtapas++;

        return Etapa::create(array_merge([
            'lote_id' => $this->lote->id,
            'nombre' => "Etapa {$this->contadorEtapas}",
            'codigo' => "L{$this->lote->numero_lote}-E{$this->contadorEtapas}",
            'superficie' => $superficie,
            'variedad_id' => $this->variedades[$variedad]->id,
            'orden' => $this->contadorEtapas,
            'is_active' => true,
            'fecha_siembra_estimada' => '2026-08-20',
        ], $extra));
    }

    protected function empresaSfId(): int
    {
        $empresa = Enterprise::firstOrCreate(
            ['slug' => 'splendidfarms'],
            ['name' => 'Splendid Farms', 'description' => 'Splendid Farms', 'is_active' => true],
        );

        return $empresa->id;
    }

    /**
     * Usuario con los permisos indicados (slugs de submodule_permission_types)
     * sobre Splendid Farms → Operación agrícola → Planeación → Programa semanal.
     *
     * @param  string[]  $permisos
     */
    protected function usuarioCon(array $permisos): User
    {
        $empresaId = $this->empresaSfId();
        Application::firstOrCreate(
            ['slug' => 'operacion-agricola', 'enterprise_id' => $empresaId],
            ['name' => 'Operación Agrícola', 'description' => 'Operaciones agrícolas', 'icon' => 'Tractor', 'path' => '/splendidfarms/operacion-agricola', 'is_active' => true],
        );
        $this->correrMigracion('2026_10_28_100200_add_planeacion_programa_semanal_submodule.php');

        $submoduloId = DB::table('submodules')->where('slug', 'programa-semanal')->value('id');
        $usuario = User::factory()->create();

        foreach ($permisos as $slug) {
            $tipoId = DB::table('submodule_permission_types')
                ->where('submodule_id', $submoduloId)
                ->where('slug', $slug)
                ->value('id');

            DB::table('user_submodule_permissions')->insert([
                'user_id' => $usuario->id,
                'submodule_id' => $submoduloId,
                'permission_type_id' => $tipoId,
                'is_granted' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $usuario;
    }

    protected function grupo(string $nombre): GrupoBalance
    {
        return GrupoBalance::where('cultivo_id', $this->cultivo->id)->where('nombre', $nombre)->firstOrFail();
    }

    protected function bodega(string $nombre): BodegaDestino
    {
        return BodegaDestino::where('nombre', $nombre)->firstOrFail();
    }

    protected function presentacion(string $nombre): Presentacion
    {
        return Presentacion::where('cultivo_id', $this->cultivo->id)->where('nombre', $nombre)->firstOrFail();
    }

    protected function tipoEmpaque(string $nombre): TipoEmpaque
    {
        return TipoEmpaque::where('nombre', $nombre)->firstOrFail();
    }

    /**
     * @param  string[]  $variedades
     * @param  int[]  $cajas  Seis cantidades, de lunes a sábado.
     */
    protected function necesidad(ProgramaSemanal $p, string $bodega, string $cliente, string $presentacion, array $variedades, ?string $empaque, array $cajas): ProgramaNecesidad
    {
        $datos = [
            'programa_id' => $p->id,
            'bodega_id' => $this->bodega($bodega)->id,
            'cliente_id' => null,
            'cliente_nombre' => $cliente,
            'cultivo_id' => $this->cultivo->id,
            'presentacion_id' => $this->presentacion($presentacion)->id,
            'tipo_empaque_id' => $empaque ? $this->tipoEmpaque($empaque)->id : null,
        ];
        foreach (array_values($cajas) as $dia => $valor) {
            $datos["cajas_{$dia}"] = $valor;
        }

        $necesidad = ProgramaNecesidad::create($datos);
        $necesidad->variedades()->attach(array_map(fn ($n) => $this->variedades[$n]->id, $variedades));

        return $necesidad;
    }

    protected function corte(ProgramaSemanal $p, string $variedad, int $dia, float $hectareas, ?Etapa $etapa = null): ProgramaCorte
    {
        return ProgramaCorte::create([
            'programa_id' => $p->id,
            'temporada_id' => $this->temporada->id,
            'etapa_id' => $etapa?->id,
            'variedad_id' => $this->variedades[$variedad]->id,
            'dia' => $dia,
            'hectareas' => $hectareas,
        ]);
    }

    /**
     * WK48 del Excel: hectáreas de las filas 7–9 como cortes sin etapa y las
     * necesidades de las filas 22–81, con clientes como texto libre.
     */
    protected function cargarWk48(ProgramaSemanal $p): void
    {
        $hectareas = [
            'Amarillo' => [7, 5, 5, 0, 3, 1],
            'Bicolor' => [10, 3, 1.5, 10, 3, 5],
            'Blanco' => [3, 2, 3.5, 0, 4, 4],
        ];
        foreach ($hectareas as $variedad => $porDia) {
            foreach ($porDia as $dia => $ha) {
                if ($ha > 0) {
                    $this->corte($p, $variedad, $dia, $ha);
                }
            }
        }

        $necesidades = [
            ['Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1480, 0, 0, 0, 0, 0]],
            ['Texas', 'Walmart', 'Tray', ['Amarillo'], 'RPC', [2500, 0, 0, 0, 0, 0]],
            ['Texas', 'Walmart', 'Minicobs', ['Amarillo'], 'RPC', [150, 0, 0, 0, 0, 0]],
            ['Texas', 'Walmart', 'Tray', ['Blanco'], 'RPC', [160, 0, 0, 0, 0, 0]],
            ['Texas', 'Twin Garden', 'Bulk', ['Amarillo'], 'RPC', [1980, 0, 0, 0, 0, 0]],
            ['Texas', 'Caruso', 'Bulk', ['Amarillo'], null, [1000, 0, 0, 0, 0, 0]],
            ['Nogales', 'El Super', 'Bulk', ['Blanco'], 'Cartón', [960, 0, 0, 0, 0, 0]],
            ['Nogales', 'Vallarta', 'Bulk', ['Blanco'], 'Cartón', [0, 0, 6584, 0, 0, 0]],
            ['Nogales', 'Shapiro', 'Bulk', ['Blanco'], 'Cartón', [0, 0, 0, 1548, 0, 2500]],
            ['Nogales', 'Vesta', 'Bulk', ['Blanco'], 'Cartón', [0, 1580, 0, 0, 1111, 0]],
            ['Nogales', 'Vesta', 'Bulk', ['Amarillo'], 'Cartón', [0, 0, 560, 0, 0, 3500]],
            ['Nogales', 'Walmart', 'Bulk', ['Amarillo', 'Bicolor'], 'RPC', [0, 850, 0, 8500, 1520, 0]],
        ];
        foreach ($necesidades as [$bodega, $cliente, $presentacion, $variedades, $empaque, $cajas]) {
            $this->necesidad($p, $bodega, $cliente, $presentacion, $variedades, $empaque, $cajas);
        }
    }
}
