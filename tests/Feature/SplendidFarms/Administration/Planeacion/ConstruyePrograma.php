<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Application;
use App\Models\Branch;
use App\Models\CierreCosecha;
use App\Models\Consignatario;
use App\Models\Cultivo;
use App\Models\EmbarqueEmpaque;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\EntityType;
use App\Models\Etapa;
use App\Models\Lote;
use App\Models\Planeacion\BodegaDestino;
use App\Models\Planeacion\GrupoBalance;
use App\Models\Planeacion\Presentacion;
use App\Models\Planeacion\ProgramaCorte;
use App\Models\Planeacion\ProgramaEmbarque;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Models\Planeacion\ProgramaSobrante;
use App\Models\Planeacion\RecetaCombinacion;
use App\Models\Planeacion\RendimientoVariedad;
use App\Models\Planeacion\TipoEmpaque;
use App\Models\ProduccionEmpaque;
use App\Models\ProduccionEmpaqueDetalle;
use App\Models\Productor;
use App\Models\Recipe;
use App\Models\SalidaCampoCosecha;
use App\Models\Temporada;
use App\Models\User;
use App\Models\Variedad;
use App\Models\ZonaCultivo;
use App\Services\Planeacion\ClientesPlaneacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ayudante de pruebas del Programa semanal: arma el elote con su temporada
 * OI 2026-2027, los catálogos sembrados, usuarios con permisos por submódulo
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

    private int $contadorRecetas = 0;

    private int $contadorPallets = 0;

    private ?int $plantaEmpaqueId = null;

    private int $contadorPlantas = 0;

    private int $contadorEmbarques = 0;

    private int $contadorTemporadas = 0;

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
     * Usuario con los permisos indicados por submódulo de Splendid Farms →
     * Administración → Planeación, p. ej. `['plan-corte' => ['view', 'capturar']]`.
     *
     * La primera vez arma la estructura como en producción: el submódulo viejo
     * «Programa semanal», su paso a Administración y la división en los seis
     * submódulos de hoy.
     *
     * @param  array<string, string[]>  $permisosPorSubmodulo
     */
    protected function usuarioCon(array $permisosPorSubmodulo): User
    {
        $empresaId = $this->empresaSfId();
        Application::firstOrCreate(
            ['slug' => 'operacion-agricola', 'enterprise_id' => $empresaId],
            ['name' => 'Operación Agrícola', 'description' => 'Operaciones agrícolas', 'icon' => 'Tractor', 'path' => '/splendidfarms/operacion-agricola', 'is_active' => true],
        );
        // La migración del submódulo crea en Operación Agrícola y la siguiente lo pasa a
        // Administración; correrla otra vez dejaría un segundo módulo en la aplicación vieja.
        if (! DB::table('modules')->where('slug', 'planeacion')->exists()) {
            $this->correrMigracion('2026_10_28_100200_add_planeacion_programa_semanal_submodule.php');
            $this->correrMigracion('2026_10_30_100000_mover_planeacion_a_administracion.php');
        }
        // Las tablas ya las crea RefreshDatabase; solo se corre si faltan.
        if (! Schema::hasTable('programa_embarques')) {
            $this->correrMigracion('2026_11_11_100000_create_programa_embarques_y_notas.php');
        }
        $moduloId = DB::table('modules')->where('slug', 'planeacion')->value('id');
        if (! DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'tablero-semanal')->exists()) {
            $this->correrMigracion('2026_11_11_100100_dividir_planeacion_en_submodulos.php');
        }

        // El submódulo «Programa de empaque» (después de Necesidades de venta) lo agrega otra migración.
        if (! DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'programa-empaque')->exists()) {
            $this->correrMigracion('2026_11_20_110100_add_programa_empaque_submodulo.php');
        }

        // «Avance de empaque» (después de Programa de empaque) también sale de su propia migración.
        if (! DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'avance-empaque')->exists()) {
            $this->correrMigracion('2026_11_20_130000_add_avance_empaque_submodulo.php');
        }

        // El permiso `enviar` del Tablero semanal también sale de su propia migración.
        $tableroId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'tablero-semanal')->value('id');
        if (! DB::table('submodule_permission_types')->where('submodule_id', $tableroId)->where('slug', 'enviar')->exists()) {
            $this->correrMigracion('2026_11_21_100000_add_enviar_permission_to_tablero_semanal.php');
        }

        // «Avance de cosecha» y «Avance de embarques» salen de una migración; `acordar`, de otra.
        if (! DB::table('submodules')->where('module_id', $moduloId)->where('slug', 'avance-cosecha')->exists()) {
            $this->correrMigracion('2026_11_25_110000_add_avance_cosecha_y_embarques_submodulos.php');
        }
        if (! DB::table('submodule_permission_types')->where('submodule_id', $tableroId)->where('slug', 'acordar')->exists()) {
            $this->correrMigracion('2026_11_25_120000_add_acordar_permission_to_tablero_semanal.php');
        }

        $usuario = User::factory()->create();

        foreach ($permisosPorSubmodulo as $submodulo => $permisos) {
            $submoduloId = DB::table('submodules')->where('module_id', $moduloId)->where('slug', $submodulo)->value('id');
            $this->assertNotNull($submoduloId, "No existe el submódulo {$submodulo}.");

            foreach ($permisos as $slug) {
                $tipoId = DB::table('submodule_permission_types')
                    ->where('submodule_id', $submoduloId)
                    ->where('slug', $slug)
                    ->value('id');
                $this->assertNotNull($tipoId, "No existe el permiso {$submodulo}.{$slug}.");

                DB::table('user_submodule_permissions')->insert([
                    'user_id' => $usuario->id,
                    'submodule_id' => $submoduloId,
                    'permission_type_id' => $tipoId,
                    'is_granted' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
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

    /** Días de antelación con que se empaca para un destino (día de empaque = llegada − días). */
    protected function antelacion(string $bodega, int $dias): void
    {
        $this->bodega($bodega)->update(['dias_antelacion' => $dias]);
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
     * @param  int[]  $cajas  Siete cantidades, de lunes a domingo.
     */
    protected function necesidad(ProgramaSemanal $p, string $bodega, string $cliente, string $presentacion, array $variedades, ?string $empaque, array $cajas): ProgramaNecesidad
    {
        // Como después de la carga inicial: el renglón queda ligado a su cliente
        // de la lista de Planeación (que se registra si falta) con su nombre canónico.
        $planCliente = app(ClientesPlaneacion::class)->resolver($cliente);
        $datos = [
            'programa_id' => $p->id,
            'bodega_id' => $this->bodega($bodega)->id,
            'cliente_id' => null,
            'plan_cliente_id' => $planCliente->id,
            'cliente_nombre' => $planCliente->nombre,
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

    /**
     * Renglón de sobrante del Programa de empaque. `$attrs` trae las columnas
     * (bodega_id, presentacion_id, tipo_empaque_id, cajas_0…cajas_6); el
     * cultivo es el elote si no se indica.
     *
     * @param  int[]  $variedadIds
     */
    protected function crearSobrante(ProgramaSemanal $p, array $attrs, array $variedadIds): ProgramaSobrante
    {
        $sobrante = ProgramaSobrante::create(array_merge(['cultivo_id' => $this->cultivo->id], $attrs, ['programa_id' => $p->id]));
        $sobrante->variedades()->attach($variedadIds);

        return $sobrante;
    }

    /** Receta de empaque de Splendid Farms con el nombre dado. */
    protected function recetaEmpaque(string $nombre): Recipe
    {
        $this->contadorRecetas++;

        return Recipe::create([
            'enterprise_id' => $this->empresaSfId(),
            'code' => "REC-PLAN-{$this->contadorRecetas}",
            'name' => $nombre,
            'recipe_type' => 'empaque',
        ]);
    }

    /**
     * Combinación de `plan_recetas_combinacion`: la receta da esa presentación
     * y ese tipo de empaque, para una variedad concreta o para cualquiera (null).
     */
    protected function combinacion(Recipe $receta, Presentacion|string $presentacion, string $empaque, Variedad|string|null $variedad = null): RecetaCombinacion
    {
        return RecetaCombinacion::create([
            'presentacion_id' => is_string($presentacion) ? $this->presentacion($presentacion)->id : $presentacion->id,
            'tipo_empaque_id' => $this->tipoEmpaque($empaque)->id,
            'variedad_id' => is_string($variedad) ? $this->variedades[$variedad]->id : $variedad?->id,
            'recipe_id' => $receta->id,
        ]);
    }

    /**
     * Pallet de `produccion_empaque` en la planta de pruebas. Con `$detalles`
     * (pares [receta|null, cajas]) es un pallet mixto: sus cajas son la suma
     * de los renglones.
     *
     * @param  list<array{0: ?Recipe, 1: int}>  $detalles
     */
    protected function pallet(string $fecha, ?Recipe $receta, int $cajas, Variedad|string|null $variedad, array $extra = [], array $detalles = []): ProduccionEmpaque
    {
        $this->contadorPallets++;
        $variedadId = is_string($variedad) ? $this->variedades[$variedad]->id : $variedad?->id;

        $pallet = ProduccionEmpaque::create(array_merge([
            'temporada_id' => $this->temporada->id,
            // Con `entity_id` en `$extra` no se crea la planta por omisión.
            'entity_id' => array_key_exists('entity_id', $extra) ? $extra['entity_id'] : $this->plantaEmpaqueId(),
            'recipe_id' => $receta?->id,
            'folio_produccion' => "PDN-PLAN-{$this->contadorPallets}",
            'fecha_produccion' => $fecha,
            'variedad_id' => $variedadId,
            'total_cajas' => $detalles ? array_sum(array_column($detalles, 1)) : $cajas,
            'peso_neto_kg' => 0,
            'status' => 'empacado',
            'is_mixto' => (bool) $detalles,
        ], $extra));

        foreach (array_values($detalles) as $i => [$recetaDetalle, $cajasDetalle]) {
            ProduccionEmpaqueDetalle::create([
                'produccion_id' => $pallet->id,
                'numero_entrada' => $i + 1,
                'recipe_id' => $recetaDetalle?->id,
                'fecha_produccion' => $fecha,
                'total_cajas' => $cajasDetalle,
            ]);
        }

        return $pallet;
    }

    /** Planta de pruebas por omisión: propia (`is_external = 0`), así cuenta en el Avance. */
    private function plantaEmpaqueId(): int
    {
        if ($this->plantaEmpaqueId === null) {
            $this->plantaEmpaqueId = $this->planta('Empaque Principal')->id;
        }

        return $this->plantaEmpaqueId;
    }

    /** Planta (entidad) de pruebas, propia o externa, en la sucursal de pruebas. */
    protected function planta(string $nombre, bool $externa = false): Entity
    {
        $this->contadorPlantas++;
        $branch = Branch::firstOrCreate(['code' => 'SF-PLAN'], [
            'enterprise_id' => $this->empresaSfId(),
            'name' => 'Casa Matriz',
            'slug' => 'casa-matriz-plan',
            'is_active' => true,
            'is_main' => true,
        ]);
        $tipo = EntityType::firstOrCreate(['code' => 'PLANTA-PLAN'], [
            'name' => 'Planta Empacadora',
            'slug' => 'planta-empacadora-plan',
            'is_active' => true,
        ]);

        return Entity::create([
            'branch_id' => $branch->id,
            'entity_type_id' => $tipo->id,
            'code' => $this->contadorPlantas === 1 ? 'EMP-PLAN' : "EMP-PLAN-{$this->contadorPlantas}",
            'name' => $nombre,
            'slug' => $this->contadorPlantas === 1 ? 'empaque-principal-plan' : "planta-plan-{$this->contadorPlantas}",
            'is_active' => true,
            'is_external' => $externa,
        ]);
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
     * Cierre de cosecha de una etapa (o sin etapa) con sus hectáreas cosechadas.
     * Temporada del elote y estado `cerrado` salvo que `$extra` diga otra cosa.
     */
    protected function cierre(?Etapa $etapa, string $fecha, float $ha, array $extra = []): CierreCosecha
    {
        return CierreCosecha::create(array_merge([
            'temporada_id' => $this->temporada->id,
            'etapa_id' => $etapa?->id,
            'lote_id' => $etapa?->lote_id,
            'fecha_inicio' => $fecha,
            'fecha_cierre' => $fecha,
            'superficie_cosechada' => $ha,
            'status' => 'cerrado',
        ], $extra));
    }

    /**
     * Salida de campo (kilos cosechados ese día) de una etapa o sin etapa. Lleva
     * lo obligatorio del esquema actual (temporada, fecha y cantidad); no está eliminada.
     */
    protected function salidaCampo(?Etapa $etapa, string $fecha, float $kg, array $extra = []): SalidaCampoCosecha
    {
        return SalidaCampoCosecha::create(array_merge([
            'temporada_id' => $this->temporada->id,
            'etapa_id' => $etapa?->id,
            'lote_id' => $etapa?->lote_id,
            'variedad_id' => $etapa?->variedad_id,
            'fecha' => $fecha,
            'cantidad' => 1,
            'peso_neto_kg' => $kg,
            'status' => 'registrada',
            'eliminado' => false,
        ], $extra));
    }

    /** Otro cultivo con su temporada abierta (1 nov 2026 – 31 may 2027), para probar el aislamiento por cultivo. */
    protected function otroCultivo(string $nombre = 'Tomate'): Temporada
    {
        $this->contadorTemporadas++;
        $autor = $this->autor ?? User::factory()->create();
        $cultivo = Cultivo::create(['nombre' => $nombre]);

        return Temporada::create([
            'cultivo_id' => $cultivo->id,
            'nombre' => "{$nombre} 2026-2027",
            'locacion' => 'Culiacán',
            'folio_temporada' => $cultivo->id.'-'.str_pad((string) (100 + $this->contadorTemporadas), 3, '0', STR_PAD_LEFT),
            'año_inicio' => 2026,
            'año_fin' => 2027,
            'fecha_inicio' => '2026-11-01',
            'fecha_fin' => '2027-05-31',
            'estado' => 'abierta',
            'user_id' => $autor->id,
        ]);
    }

    /** Celda de embarque programado: `$cargas` cargas del tipo (`full`|`sencillo`) hacia la bodega el día `$dia` (0 = lunes). */
    protected function cargaPlan(ProgramaSemanal $p, string $bodega, string $tipo, int $dia, int $cargas): ProgramaEmbarque
    {
        return ProgramaEmbarque::create([
            'programa_id' => $p->id,
            'cultivo_id' => $this->cultivo->id,
            'bodega_id' => $this->bodega($bodega)->id,
            'tipo' => $tipo,
            'dia' => $dia,
            'cargas' => $cargas,
        ]);
    }

    /**
     * Embarque real de la planta de pruebas (propia), con folio único y 1000 cajas
     * salvo que `$extra` diga otra cosa. `$destino` es el texto libre del destino.
     */
    protected function embarqueReal(string $fecha, string $status, ?string $destino, array $extra = []): EmbarqueEmpaque
    {
        $this->contadorEmbarques++;

        return EmbarqueEmpaque::create(array_merge([
            'temporada_id' => $this->temporada->id,
            'entity_id' => $this->plantaEmpaqueId(),
            'folio_embarque' => "EMB-PLAN-{$this->contadorEmbarques}",
            'tipo_venta' => 'exportacion',
            'fecha_embarque' => $fecha,
            'status' => $status,
            'destino' => $destino,
            'total_cajas' => 1000,
        ], $extra));
    }

    /** Consignatario de Splendid Farms (catálogo de destinos de los embarques), con su bodega. */
    protected function consignatario(string $nombre, ?string $bodega): Consignatario
    {
        return Consignatario::create([
            'enterprise_id' => $this->empresaSfId(),
            'nombre' => $nombre,
            'bodega' => $bodega,
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
            ['Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1480, 0, 0, 0, 0, 0, 0]],
            ['Texas', 'Walmart', 'Tray', ['Amarillo'], 'RPC', [2500, 0, 0, 0, 0, 0, 0]],
            ['Texas', 'Walmart', 'Minicobs', ['Amarillo'], 'RPC', [150, 0, 0, 0, 0, 0, 0]],
            ['Texas', 'Walmart', 'Tray', ['Blanco'], 'RPC', [160, 0, 0, 0, 0, 0, 0]],
            ['Texas', 'Twin Garden', 'Bulk', ['Amarillo'], 'RPC', [1980, 0, 0, 0, 0, 0, 0]],
            ['Texas', 'Caruso', 'Bulk', ['Amarillo'], null, [1000, 0, 0, 0, 0, 0, 0]],
            ['Nogales', 'El Super', 'Bulk', ['Blanco'], 'Cartón', [960, 0, 0, 0, 0, 0, 0]],
            ['Nogales', 'Vallarta', 'Bulk', ['Blanco'], 'Cartón', [0, 0, 6584, 0, 0, 0, 0]],
            ['Nogales', 'Shapiro', 'Bulk', ['Blanco'], 'Cartón', [0, 0, 0, 1548, 0, 2500, 0]],
            ['Nogales', 'Vesta', 'Bulk', ['Blanco'], 'Cartón', [0, 1580, 0, 0, 1111, 0, 0]],
            ['Nogales', 'Vesta', 'Bulk', ['Amarillo'], 'Cartón', [0, 0, 560, 0, 0, 3500, 0]],
            ['Nogales', 'Walmart', 'Bulk', ['Amarillo', 'Bicolor'], 'RPC', [0, 850, 0, 8500, 1520, 0, 0]],
        ];
        foreach ($necesidades as [$bodega, $cliente, $presentacion, $variedades, $empaque, $cajas]) {
            $this->necesidad($p, $bodega, $cliente, $presentacion, $variedades, $empaque, $cajas);
        }
    }
}
