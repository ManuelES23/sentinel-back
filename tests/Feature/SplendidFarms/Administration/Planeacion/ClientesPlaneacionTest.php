<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\CRM\CrmCliente;
use App\Models\Enterprise;
use App\Models\Planeacion\PlanCliente;
use App\Models\Planeacion\ProgramaNecesidad;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\ClientesPlaneacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lista propia de clientes de Planeación: clave normalizada, resolver/crear,
 * sugerencias, carga inicial de la migración y su uso en la hoja de
 * Necesidades, en «copiar anterior» y en `GET clientes`.
 */
class ClientesPlaneacionTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const BASE = '/api/splendidfarms/administration/planeacion';

    private const MIGRACION = '2026_11_23_110000_create_plan_clientes_table.php';

    private ProgramaSemanal $programa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        $this->programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
    }

    private function servicio(): ClientesPlaneacion
    {
        return app(ClientesPlaneacion::class);
    }

    private function comoVendedor(): void
    {
        Sanctum::actingAs($this->usuarioCon(['necesidades-venta' => ['view', 'capturar']]));
    }

    private function empresaSbp(): Enterprise
    {
        return Enterprise::firstOrCreate(
            ['slug' => 'splendidbyporvenir'],
            ['name' => 'Splendid by Porvenir', 'description' => 'SBP', 'is_active' => true],
        );
    }

    private function nueva(string $clave, array $cambios = []): array
    {
        return array_merge([
            'clave' => $clave,
            'bodega_id' => $this->bodega('Nogales')->id,
            'cliente_nombre' => 'Walmart',
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'variedad_ids' => [$this->variedades['Amarillo']->id],
            'cajas' => [10, 0, 0, 0, 0, 0, 0],
        ], $cambios);
    }

    private function guardar(array $cuerpo, ?ProgramaSemanal $p = null)
    {
        return $this->putJson(self::BASE.'/programa/'.($p ?? $this->programa)->id.'/necesidades/hoja', $cuerpo);
    }

    private function correrCargaInicial(): void
    {
        (require base_path('database/migrations/'.self::MIGRACION))->up();
    }

    /** Deja las necesidades como antes de la migración: sin liga y sin lista. */
    private function comoAntesDeLaMigracion(array $nombres = []): void
    {
        DB::table('programa_necesidades')->update(['plan_cliente_id' => null]);
        DB::table('plan_clientes')->delete();
        foreach ($nombres as $id => $nombre) {
            DB::table('programa_necesidades')->where('id', $id)->update(['cliente_nombre' => $nombre]);
        }
    }

    // ── Clave ───────────────────────────────────────────

    public function test_clave_ignora_mayusculas_acentos_y_espacios(): void
    {
        $this->assertSame('walmart', ClientesPlaneacion::clave('  WalMart  '));
        $this->assertSame(ClientesPlaneacion::clave('walmart'), ClientesPlaneacion::clave('  WalMart  '));
        $this->assertSame('walmart de mexico', ClientesPlaneacion::clave('Walmart   de  México'));
        $this->assertSame('el nino', ClientesPlaneacion::clave("El\tNiño"));
        $this->assertSame('', ClientesPlaneacion::clave('   '));
    }

    public function test_la_clave_de_la_migracion_es_la_del_servicio(): void
    {
        $migracion = require base_path('database/migrations/'.self::MIGRACION);
        foreach (['  WalMart  ', 'Walmart   de  México', "El\tNiño", 'ÁÉÍÓÚ ü ç', '', 'Caruso 100%_x', str_repeat('ß', 200)] as $nombre) {
            $this->assertSame(ClientesPlaneacion::clave($nombre), $migracion->clave($nombre), "Clave distinta para «{$nombre}».");
        }
    }

    // ── resolver ────────────────────────────────────────

    public function test_resolver_por_clave_devuelve_el_existente_sin_crear_otro(): void
    {
        $walmart = $this->servicio()->resolver('Walmart');

        foreach (['walmart', '  WALMART ', 'Wálmart'] as $variante) {
            $this->assertSame($walmart->id, $this->servicio()->resolver($variante)->id);
        }
        $this->assertSame(1, PlanCliente::count());
        $this->assertSame('Walmart', $walmart->fresh()->nombre);
    }

    public function test_resolver_crea_el_nuevo_con_el_nombre_limpio(): void
    {
        $cliente = $this->servicio()->resolver('  El   Super  ');

        $this->assertTrue($cliente->exists);
        $this->assertSame('El Super', $cliente->nombre);
        $this->assertSame('el super', $cliente->nombre_clave);
        $this->assertTrue($cliente->activo);
    }

    public function test_resolver_por_id_manda_y_reactiva_al_inactivo(): void
    {
        $vesta = $this->servicio()->resolver('Vesta');
        $vesta->update(['activo' => false]);

        // Por id gana aunque el nombre diga otra cosa.
        $porId = $this->servicio()->resolver('Otro nombre', $vesta->id);
        $this->assertSame($vesta->id, $porId->id);
        $this->assertTrue($vesta->fresh()->activo);
        $this->assertSame(1, PlanCliente::count());

        // Escribir el nombre de un inactivo también lo reactiva.
        $vesta->update(['activo' => false]);
        $this->assertSame($vesta->id, $this->servicio()->resolver('VESTA')->id);
        $this->assertTrue($vesta->fresh()->activo);

        // Un id que no existe cae al nombre.
        $this->assertSame($vesta->id, $this->servicio()->resolver('vesta', 999999)->id);
    }

    public function test_resolver_vacio_o_largo_es_422(): void
    {
        foreach (['', '   ', str_repeat('a', 151)] as $nombre) {
            try {
                $this->servicio()->resolver($nombre);
                $this->fail("Se esperaba 422 para «{$nombre}».");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('nombre', $e->errors());
            }
        }
        $this->assertSame(0, PlanCliente::count());

        // 150 sí cabe.
        $this->assertSame(150, mb_strlen($this->servicio()->resolver(str_repeat('a', 150))->nombre));
    }

    public function test_resolver_en_carrera_reutiliza_al_ganador(): void
    {
        // Entre la búsqueda y el alta otro guardado registra el mismo cliente.
        PlanCliente::creating(function (PlanCliente $nuevo) {
            if ($nuevo->nombre_clave === 'shapiro' && ! DB::table('plan_clientes')->where('nombre_clave', 'shapiro')->exists()) {
                DB::table('plan_clientes')->insert([
                    'nombre' => 'Shapiro',
                    'nombre_clave' => 'shapiro',
                    'activo' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $cliente = $this->servicio()->resolver('SHAPIRO');

        $this->assertSame(1, PlanCliente::where('nombre_clave', 'shapiro')->count());
        $this->assertSame('Shapiro', $cliente->nombre);
        $this->assertSame(PlanCliente::where('nombre_clave', 'shapiro')->value('id'), $cliente->id);
    }

    // ── sugerir ─────────────────────────────────────────

    public function test_sugerir_filtra_activos_ordena_y_limita(): void
    {
        foreach (['Walmart', 'Walmart Texas', 'El Super', 'Wal_mart', 'Caruso 100%'] as $nombre) {
            $this->servicio()->resolver($nombre);
        }
        PlanCliente::where('nombre', 'Walmart Texas')->update(['activo' => false]);

        $this->assertSame(['Wal_mart', 'Walmart'], array_column($this->servicio()->sugerir('WAL'), 'nombre'));
        $this->assertSame(['El Super'], array_column($this->servicio()->sugerir('súper'), 'nombre'));
        // `%` y `_` son literales.
        $this->assertSame(['Wal_mart'], array_column($this->servicio()->sugerir('l_m'), 'nombre'));
        $this->assertSame(['Caruso 100%'], array_column($this->servicio()->sugerir('0%'), 'nombre'));
        $this->assertSame(['Caruso 100%'], array_column($this->servicio()->sugerir('%'), 'nombre'));
        $this->assertSame([], $this->servicio()->sugerir('x_y'));

        $primero = $this->servicio()->sugerir('walmart')[0];
        $this->assertSame(['id', 'nombre'], array_keys($primero));

        for ($i = 25; $i >= 1; $i--) {
            $this->servicio()->resolver(sprintf('Cliente %02d', $i));
        }
        $todos = $this->servicio()->sugerir('cliente');
        $this->assertCount(20, $todos);
        $this->assertSame('Cliente 01', $todos[0]['nombre']);
        $this->assertSame('Cliente 20', $todos[19]['nombre']);
        $this->assertCount(20, $this->servicio()->sugerir());
    }

    // ── Carga inicial ───────────────────────────────────

    public function test_carga_inicial_trae_el_crm_y_los_nombres_y_liga_todas_las_necesidades(): void
    {
        $sbp = $this->empresaSbp();
        $otra = Enterprise::create(['name' => 'Otra', 'slug' => 'otra-empresa', 'description' => 'x', 'is_active' => true]);
        CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'WALMART']);
        CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'Vallarta']);
        CrmCliente::create(['empresa_id' => $otra->id, 'nombre' => 'Ajeno']);
        CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'Borrado'])->delete();

        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $n1 = $this->necesidad($anterior, 'Texas', 'x', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $n2 = $this->necesidad($this->programa, 'Texas', 'x', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $n3 = $this->necesidad($this->programa, 'Texas', 'x', 'Tray', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $n4 = $this->necesidad($anterior, 'Texas', 'x', 'Tray', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $n5 = $this->necesidad($this->programa, 'Nogales', 'x', 'Bulk', ['Blanco'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $n6 = $this->necesidad($this->programa, 'Nogales', 'x', 'Tray', ['Blanco'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $this->comoAntesDeLaMigracion([
            $n1->id => 'Walmart',
            $n2->id => 'walmart ',
            $n3->id => 'WALMART',
            $n4->id => 'El  Súper',
            $n5->id => 'el super',
            $n6->id => 'Twin Garden',
        ]);

        $this->correrCargaInicial();

        // Del CRM (gana su escritura) y de las necesidades (gana la más antigua).
        $this->assertEqualsCanonicalizing(
            ['WALMART', 'Vallarta', 'El Súper', 'Twin Garden'],
            PlanCliente::pluck('nombre')->all(),
        );
        $walmart = PlanCliente::where('nombre_clave', 'walmart')->firstOrFail();
        $super = PlanCliente::where('nombre_clave', 'el super')->firstOrFail();
        $twin = PlanCliente::where('nombre_clave', 'twin garden')->firstOrFail();
        $this->assertSame($walmart->id, $n1->fresh()->plan_cliente_id);
        $this->assertSame($walmart->id, $n2->fresh()->plan_cliente_id);
        $this->assertSame($walmart->id, $n3->fresh()->plan_cliente_id);
        $this->assertSame($super->id, $n4->fresh()->plan_cliente_id);
        $this->assertSame($super->id, $n5->fresh()->plan_cliente_id);
        $this->assertSame($twin->id, $n6->fresh()->plan_cliente_id);
        $this->assertSame(0, ProgramaNecesidad::whereNull('plan_cliente_id')->count());

        // No reescribe los nombres de las necesidades.
        $this->assertSame('walmart ', $n2->fresh()->cliente_nombre);
        $this->assertSame('el super', $n5->fresh()->cliente_nombre);

        // Una segunda corrida no duplica ni cambia ligas.
        $antes = ProgramaNecesidad::orderBy('id')->pluck('plan_cliente_id', 'id')->all();
        $this->correrCargaInicial();
        $this->assertSame(4, PlanCliente::count());
        $this->assertSame($antes, ProgramaNecesidad::orderBy('id')->pluck('plan_cliente_id', 'id')->all());
    }

    public function test_carga_inicial_sin_empresa_de_sbp_ni_necesidades_no_falla(): void
    {
        $this->correrCargaInicial();
        $this->assertSame(0, PlanCliente::count());

        // Sin SBP, solo los nombres de las necesidades.
        $n = $this->necesidad($this->programa, 'Texas', 'Caruso', 'Bulk', ['Amarillo'], null, [1, 0, 0, 0, 0, 0, 0]);
        $this->comoAntesDeLaMigracion();
        $this->correrCargaInicial();
        $this->assertSame(['Caruso'], PlanCliente::pluck('nombre')->all());
        $this->assertSame(PlanCliente::value('id'), $n->fresh()->plan_cliente_id);
    }

    public function test_down_quita_la_columna_y_la_tabla_y_up_las_regresa(): void
    {
        $n = $this->necesidad($this->programa, 'Texas', 'Caruso', 'Bulk', ['Amarillo'], null, [1, 0, 0, 0, 0, 0, 0]);
        $migracion = require base_path('database/migrations/'.self::MIGRACION);

        $migracion->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('plan_clientes'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('programa_necesidades', 'plan_cliente_id'));
        $this->assertSame('Caruso', DB::table('programa_necesidades')->where('id', $n->id)->value('cliente_nombre'));

        $migracion->up();
        $this->assertSame(PlanCliente::where('nombre_clave', 'caruso')->value('id'), $n->fresh()->plan_cliente_id);
    }

    public function test_importar_del_crm_agrega_solo_los_que_faltan(): void
    {
        $this->assertSame(0, $this->servicio()->importarDelCrm());

        $sbp = $this->empresaSbp();
        $this->servicio()->resolver('Walmart');
        CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'walmart']);
        CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'Shapiro']);
        CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'SHAPIRO']);
        CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'Borrado'])->delete();

        $this->assertSame(1, $this->servicio()->importarDelCrm());
        $this->assertEqualsCanonicalizing(['Walmart', 'Shapiro'], PlanCliente::pluck('nombre')->all());
        $this->assertSame(0, $this->servicio()->importarDelCrm());
    }

    // ── Hoja de Necesidades ─────────────────────────────

    public function test_hoja_registra_el_cliente_nuevo_y_lo_liga(): void
    {
        $this->comoVendedor();

        $respuesta = $this->guardar(['nuevas' => [$this->nueva('a', ['cliente_nombre' => '  Cliente   Nuevo '])]])->assertOk();

        $cliente = PlanCliente::where('nombre_clave', 'cliente nuevo')->firstOrFail();
        $this->assertSame('Cliente Nuevo', $cliente->nombre);
        $n = ProgramaNecesidad::findOrFail($respuesta->json('claves.a'));
        $this->assertSame($cliente->id, $n->plan_cliente_id);
        $this->assertSame('Cliente Nuevo', $n->cliente_nombre);
        $this->assertNull($n->cliente_id);
        $this->assertSame($cliente->id, $respuesta->json('data.necesidades.0.plan_cliente_id'));
    }

    public function test_hoja_con_otras_mayusculas_guarda_el_nombre_canonico_sin_duplicar(): void
    {
        $this->comoVendedor();
        $walmart = $this->servicio()->resolver('Walmart');

        $respuesta = $this->guardar(['nuevas' => [
            $this->nueva('a', ['cliente_nombre' => 'walmart']),
            $this->nueva('b', ['cliente_nombre' => 'WALMART ', 'presentacion_id' => $this->presentacion('Tray')->id]),
            // Dos renglones del mismo cliente nuevo en la misma petición: uno solo.
            $this->nueva('c', ['cliente_nombre' => 'Vesta']),
            $this->nueva('d', ['cliente_nombre' => 'vesta', 'presentacion_id' => $this->presentacion('Tray')->id]),
        ]])->assertOk();

        foreach (['a', 'b'] as $clave) {
            $n = ProgramaNecesidad::findOrFail($respuesta->json("claves.$clave"));
            $this->assertSame($walmart->id, $n->plan_cliente_id);
            $this->assertSame('Walmart', $n->cliente_nombre);
        }
        $this->assertSame(2, PlanCliente::count());
        $this->assertSame('Vesta', ProgramaNecesidad::findOrFail($respuesta->json('claves.d'))->cliente_nombre);
    }

    public function test_hoja_plan_cliente_id_explicito_gana(): void
    {
        $this->comoVendedor();
        $caruso = $this->servicio()->resolver('Caruso');

        $respuesta = $this->guardar(['nuevas' => [
            $this->nueva('a', ['plan_cliente_id' => $caruso->id, 'cliente_nombre' => 'Otro']),
            $this->nueva('b', ['plan_cliente_id' => $caruso->id, 'cliente_nombre' => null, 'presentacion_id' => $this->presentacion('Tray')->id]),
        ]])->assertOk();

        foreach (['a', 'b'] as $clave) {
            $n = ProgramaNecesidad::findOrFail($respuesta->json("claves.$clave"));
            $this->assertSame($caruso->id, $n->plan_cliente_id);
            $this->assertSame('Caruso', $n->cliente_nombre);
        }
        $this->assertSame(1, PlanCliente::count());

        // Un id que no existe sin nombre es 422 en el id; sin nada, en el nombre.
        $this->guardar(['nuevas' => [$this->nueva('x', ['plan_cliente_id' => 999999, 'cliente_nombre' => null])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.plan_cliente_id');
        $this->guardar(['nuevas' => [$this->nueva('x', ['cliente_nombre' => null])]])
            ->assertUnprocessable()->assertJsonValidationErrors('nuevas.0.cliente_nombre');
    }

    public function test_hoja_con_422_en_otra_celda_no_deja_el_cliente_nuevo(): void
    {
        $this->comoVendedor();
        $n = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $antes = PlanCliente::count();

        // El primer renglón registra el cliente nuevo; el segundo falla después
        // (variedades de grupos distintos), cuando el cliente ya se creó.
        $this->guardar(['nuevas' => [
            $this->nueva('a', ['cliente_nombre' => 'Cliente Fantasma']),
            $this->nueva('b', ['cliente_nombre' => 'Otro Fantasma', 'variedad_ids' => [$this->variedades['Amarillo']->id, $this->variedades['Blanco']->id]]),
        ]])->assertUnprocessable()->assertJsonValidationErrors('nuevas.1.variedad_ids');

        $this->assertSame($antes, PlanCliente::count());
        $this->assertNull(PlanCliente::where('nombre_clave', 'cliente fantasma')->first());
        $this->assertSame(1, ProgramaNecesidad::count());
    }

    public function test_hoja_cambio_de_cliente_en_un_renglon_existente(): void
    {
        $this->comoVendedor();
        $crm = CrmCliente::create(['empresa_id' => $this->empresaSbp()->id, 'nombre' => 'Walmart']);
        $n = $this->necesidad($this->programa, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 2, 3, 4, 5, 6, 0]);
        $n->update(['cliente_id' => $crm->id]);
        $vallarta = $this->servicio()->resolver('Vallarta');

        $this->guardar(['cambios' => [['id' => $n->id, 'cliente_nombre' => 'VALLARTA']]])->assertOk();

        $fresca = $n->fresh();
        $this->assertSame($vallarta->id, $fresca->plan_cliente_id);
        $this->assertSame('Vallarta', $fresca->cliente_nombre);
        $this->assertNull($fresca->cliente_id);
        $this->assertSame([1, 2, 3, 4, 5, 6, 0], $fresca->cajasPorDia());

        // Por id, a un cliente nuevo de la lista.
        $shapiro = $this->servicio()->resolver('Shapiro');
        $this->guardar(['cambios' => [['id' => $n->id, 'plan_cliente_id' => $shapiro->id]]])->assertOk();
        $this->assertSame($shapiro->id, $n->fresh()->plan_cliente_id);
        $this->assertSame('Shapiro', $n->fresh()->cliente_nombre);
    }

    // ── Copiar anterior, payload y sugerencias de la semana ──

    public function test_copiar_anterior_conserva_el_cliente_de_la_lista(): void
    {
        $this->comoVendedor();
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $ligada = $this->necesidad($anterior, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $crm = CrmCliente::create(['empresa_id' => $this->empresaSbp()->id, 'nombre' => 'Walmart']);
        $ligada->update(['cliente_id' => $crm->id]);
        // La lista ya tiene el nombre corregido: la copia lo toma de ahí.
        PlanCliente::whereKey($ligada->plan_cliente_id)->update(['nombre' => 'Walmart México', 'nombre_clave' => 'walmart mexico']);
        // Un renglón viejo sin liga se liga al copiarlo.
        $suelta = $this->necesidad($anterior, 'Texas', 'Caruso', 'Tray', ['Amarillo'], null, [2, 0, 0, 0, 0, 0, 0]);
        $suelta->update(['plan_cliente_id' => null]);
        PlanCliente::where('nombre_clave', 'caruso')->delete();

        $this->postJson(self::BASE.'/programa/'.$this->programa->id.'/necesidades/copiar-anterior')
            ->assertOk()->assertJsonPath('data.copiadas', 2);

        $copias = ProgramaNecesidad::where('programa_id', $this->programa->id)->orderBy('id')->get();
        $this->assertSame($ligada->plan_cliente_id, $copias[0]->plan_cliente_id);
        $this->assertSame('Walmart México', $copias[0]->cliente_nombre);
        $this->assertNull($copias[0]->cliente_id);
        $caruso = PlanCliente::where('nombre_clave', 'caruso')->firstOrFail();
        $this->assertSame($caruso->id, $copias[1]->plan_cliente_id);
        $this->assertSame('Caruso', $copias[1]->cliente_nombre);
    }

    public function test_payload_y_clientes_previos_traen_plan_cliente_id(): void
    {
        $this->comoVendedor();
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $previa = $this->necesidad($anterior, 'Texas', 'Walmart', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $this->necesidad($anterior, 'Nogales', 'Vesta', 'Bulk', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
        $actual = $this->necesidad($this->programa, 'Nogales', 'vesta', 'Tray', ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);

        $data = $this->getJson(self::BASE.'/programa?lunes=2026-11-23')->assertOk()->json('data');

        $renglon = collect($data['necesidades'])->firstWhere('id', $actual->id);
        $this->assertSame($actual->plan_cliente_id, $renglon['plan_cliente_id']);
        $this->assertSame('Vesta', $renglon['cliente_nombre']);
        $this->assertArrayHasKey('cliente_id', $renglon);
        $this->assertFalse($renglon['en_crm']);

        // Vesta/Nogales ya está esta semana; Walmart/Texas no.
        $this->assertSame([[
            'cliente_id' => null,
            'plan_cliente_id' => $previa->plan_cliente_id,
            'cliente_nombre' => 'Walmart',
            'bodega_id' => $this->bodega('Texas')->id,
        ]], $data['clientes_previos']);
    }

    // ── GET clientes ────────────────────────────────────

    public function test_get_clientes_lista_los_de_planeacion_y_no_los_del_crm(): void
    {
        Sanctum::actingAs($this->usuarioCon(['necesidades-venta' => ['view']]));
        CrmCliente::create(['empresa_id' => $this->empresaSbp()->id, 'nombre' => 'Walmart del CRM']);
        $walmart = $this->servicio()->resolver('Walmart');
        $this->servicio()->resolver('Walmart Viejo')->update(['activo' => false]);
        $this->servicio()->resolver('El Super');

        $this->getJson(self::BASE.'/clientes?q=wal')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [['id' => $walmart->id, 'nombre' => 'Walmart']]);
        $this->assertCount(2, $this->getJson(self::BASE.'/clientes')->assertOk()->json('data'));
    }

    public function test_get_clientes_sin_permiso_de_ver_necesidades_es_403(): void
    {
        Sanctum::actingAs($this->usuarioCon(['plan-corte' => ['view']]));

        $this->getJson(self::BASE.'/clientes?q=wal')->assertForbidden();
    }
}
