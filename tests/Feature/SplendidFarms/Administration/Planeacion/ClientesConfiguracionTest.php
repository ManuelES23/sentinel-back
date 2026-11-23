<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\CRM\CrmCliente;
use App\Models\Enterprise;
use App\Models\Planeacion\PlanCliente;
use App\Models\Planeacion\ProgramaSemanal;
use App\Services\Planeacion\ClientesPlaneacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Configuración → Clientes de Planeación: lista con conteo en `GET
 * configuracion`, alta, renombrar (con las necesidades), activar/desactivar y
 * «Traer del CRM». También el nombre actual en `clientes_previos` y la carga
 * inicial de la migración después de un renombrado.
 */
class ClientesConfiguracionTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/administration/planeacion';

    private const MIGRACION = '2026_11_23_110000_create_plan_clientes_table.php';

    private ProgramaSemanal $programa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearElote();
        $this->programa = ProgramaSemanal::create(['lunes' => '2026-11-23']);
    }

    private function conPermisos(array $permisos = ['configuracion' => ['view', 'editar']]): void
    {
        Sanctum::actingAs($this->usuarioCon($permisos));
    }

    private function servicio(): ClientesPlaneacion
    {
        return app(ClientesPlaneacion::class);
    }

    private function empresaSbp(): Enterprise
    {
        return Enterprise::firstOrCreate(
            ['slug' => 'splendidbyporvenir'],
            ['name' => 'Splendid by Porvenir', 'description' => 'SBP', 'is_active' => true],
        );
    }

    private function renglon(string $cliente, string $bodega = 'Texas', string $presentacion = 'Bulk', ?ProgramaSemanal $p = null)
    {
        return $this->necesidad($p ?? $this->programa, $bodega, $cliente, $presentacion, ['Amarillo'], 'RPC', [1, 0, 0, 0, 0, 0, 0]);
    }

    // ── GET configuracion ───────────────────────────────

    public function test_configuracion_trae_los_clientes_con_conteo_activos_primero(): void
    {
        $this->conPermisos(['configuracion' => ['view']]);
        $this->renglon('Walmart');
        $this->renglon('Walmart', 'Nogales');
        $this->renglon('Caruso');
        $this->servicio()->resolver('Bodega Aurrera');
        $this->servicio()->resolver('Antiguo')->update(['activo' => false]);

        $clientes = $this->getJson(self::URL.'/configuracion')->assertOk()->json('data.clientes');

        $id = fn (string $n) => PlanCliente::where('nombre', $n)->value('id');
        $this->assertSame([
            ['id' => $id('Bodega Aurrera'), 'nombre' => 'Bodega Aurrera', 'activo' => true, 'necesidades' => 0],
            ['id' => $id('Caruso'), 'nombre' => 'Caruso', 'activo' => true, 'necesidades' => 1],
            ['id' => $id('Walmart'), 'nombre' => 'Walmart', 'activo' => true, 'necesidades' => 2],
            ['id' => $id('Antiguo'), 'nombre' => 'Antiguo', 'activo' => false, 'necesidades' => 0],
        ], $clientes);
    }

    // ── POST configuracion/clientes ─────────────────────

    public function test_agregar_crea_el_cliente_con_el_nombre_limpio(): void
    {
        $this->conPermisos();

        $r = $this->postJson(self::URL.'/configuracion/clientes', ['nombre' => '  El   Super '])->assertCreated();

        $cliente = PlanCliente::firstOrFail();
        $r->assertJsonPath('success', true)
            ->assertJsonPath('data.cliente', ['id' => $cliente->id, 'nombre' => 'El Super', 'activo' => true, 'necesidades' => 0]);
        $this->assertSame('el super', $cliente->nombre_clave);
    }

    public function test_agregar_un_nombre_existente_lo_reactiva_sin_duplicar(): void
    {
        $this->conPermisos();
        $walmart = $this->servicio()->resolver('Walmart');
        $this->renglon('Walmart');
        $walmart->update(['activo' => false]);

        $this->postJson(self::URL.'/configuracion/clientes', ['nombre' => 'WALMART'])
            ->assertCreated()
            ->assertJsonPath('data.cliente', ['id' => $walmart->id, 'nombre' => 'Walmart', 'activo' => true, 'necesidades' => 1]);

        $this->assertSame(1, PlanCliente::count());
        $this->assertTrue($walmart->fresh()->activo);
    }

    public function test_agregar_valida_el_nombre(): void
    {
        $this->conPermisos();

        $this->postJson(self::URL.'/configuracion/clientes', ['nombre' => '   '])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'El cliente es obligatorio.']);
        $this->postJson(self::URL.'/configuracion/clientes', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'El cliente es obligatorio.']);
        $this->postJson(self::URL.'/configuracion/clientes', ['nombre' => ['x']])
            ->assertUnprocessable()->assertJsonValidationErrors('nombre');
        $this->postJson(self::URL.'/configuracion/clientes', ['nombre' => str_repeat('a', 151)])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'El nombre del cliente no puede pasar de 150 caracteres.']);
        $this->postJson(self::URL.'/configuracion/clientes', ['nombre' => '😀😀'])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'El nombre del cliente debe tener letras o números.']);

        $this->assertSame(0, PlanCliente::count());
        $this->postJson(self::URL.'/configuracion/clientes', ['nombre' => str_repeat('a', 150)])->assertCreated();
    }

    // ── PUT configuracion/clientes/{cliente} ────────────

    public function test_renombrar_actualiza_el_nombre_de_todas_sus_necesidades(): void
    {
        $this->conPermisos(['configuracion' => ['view', 'editar'], 'tablero-semanal' => ['view']]);
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $a = $this->renglon('Walmart');
        $b = $this->renglon('Walmart', 'Nogales', 'Bulk', $anterior);
        $otro = $this->renglon('Caruso');
        $walmart = PlanCliente::where('nombre', 'Walmart')->firstOrFail();
        // Un renglón viejo con otra escritura del mismo cliente también se renombra.
        DB::table('programa_necesidades')->where('id', $b->id)->update(['cliente_nombre' => 'walmart ']);

        $this->putJson(self::URL.'/configuracion/clientes/'.$walmart->id, ['nombre' => ' Walmart  México '])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cliente', ['id' => $walmart->id, 'nombre' => 'Walmart México', 'activo' => true, 'necesidades' => 2]);

        $this->assertSame('Walmart México', $walmart->fresh()->nombre);
        $this->assertSame('walmart mexico', $walmart->fresh()->nombre_clave);
        $this->assertSame('Walmart México', $a->fresh()->cliente_nombre);
        $this->assertSame('Walmart México', $b->fresh()->cliente_nombre);
        $this->assertSame('Caruso', $otro->fresh()->cliente_nombre);

        // El Tablero muestra el nombre nuevo.
        $renglon = collect($this->getJson(self::URL.'/programa?lunes=2026-11-23')->assertOk()->json('data.necesidades'))
            ->firstWhere('id', $a->id);
        $this->assertSame('Walmart México', $renglon['cliente_nombre']);
    }

    public function test_renombrar_al_nombre_de_otro_cliente_es_422_sin_cambios(): void
    {
        $this->conPermisos();
        $a = $this->renglon('Walmart');
        $this->renglon('Caruso');
        $walmart = PlanCliente::where('nombre', 'Walmart')->firstOrFail();

        $this->putJson(self::URL.'/configuracion/clientes/'.$walmart->id, ['nombre' => 'CARUSO'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['nombre' => 'Ya existe un cliente con ese nombre.']);

        $this->assertSame('Walmart', $walmart->fresh()->nombre);
        $this->assertSame('walmart', $walmart->fresh()->nombre_clave);
        $this->assertSame('Walmart', $a->fresh()->cliente_nombre);
    }

    public function test_renombrar_solo_cambiando_mayusculas_es_valido(): void
    {
        $this->conPermisos();
        $a = $this->renglon('walmart');
        $walmart = PlanCliente::firstOrFail();

        $this->putJson(self::URL.'/configuracion/clientes/'.$walmart->id, ['nombre' => 'WalMart'])
            ->assertOk()
            ->assertJsonPath('data.cliente.nombre', 'WalMart');

        $this->assertSame('WalMart', $a->fresh()->cliente_nombre);
        $this->assertSame(1, PlanCliente::count());
    }

    public function test_renombrar_valida_el_nombre(): void
    {
        $this->conPermisos();
        $walmart = $this->servicio()->resolver('Walmart');
        $url = self::URL.'/configuracion/clientes/'.$walmart->id;

        $this->putJson($url, ['nombre' => '  '])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'El cliente es obligatorio.']);
        $this->putJson($url, ['nombre' => str_repeat('a', 151)])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'El nombre del cliente no puede pasar de 150 caracteres.']);
        $this->putJson($url, ['nombre' => '😀😀'])
            ->assertUnprocessable()->assertJsonValidationErrors(['nombre' => 'El nombre del cliente debe tener letras o números.']);
        $this->putJson($url, ['activo' => 'quizá'])
            ->assertUnprocessable()->assertJsonValidationErrors('activo');

        $this->assertSame('Walmart', $walmart->fresh()->nombre);
        $this->putJson(self::URL.'/configuracion/clientes/999999', ['nombre' => 'X'])->assertNotFound();
    }

    public function test_desactivar_y_activar_conserva_el_nombre_de_las_necesidades(): void
    {
        $this->conPermisos(['configuracion' => ['view', 'editar'], 'necesidades-venta' => ['view']]);
        $a = $this->renglon('Walmart');
        $walmart = PlanCliente::firstOrFail();

        $this->putJson(self::URL.'/configuracion/clientes/'.$walmart->id, ['activo' => false])
            ->assertOk()
            ->assertJsonPath('data.cliente', ['id' => $walmart->id, 'nombre' => 'Walmart', 'activo' => false, 'necesidades' => 1]);

        $this->assertFalse($walmart->fresh()->activo);
        $this->assertSame('Walmart', $a->fresh()->cliente_nombre);
        $this->assertSame($walmart->id, $a->fresh()->plan_cliente_id);
        $this->getJson(self::URL.'/clientes?q=wal')->assertOk()->assertJsonPath('data', []);

        $this->putJson(self::URL.'/configuracion/clientes/'.$walmart->id, ['activo' => true])
            ->assertOk()->assertJsonPath('data.cliente.activo', true);
        $this->getJson(self::URL.'/clientes?q=wal')->assertOk()
            ->assertJsonPath('data', [['id' => $walmart->id, 'nombre' => 'Walmart']]);
    }

    // ── POST configuracion/clientes/importar-crm ───────

    public function test_importar_del_crm_agrega_solo_los_que_faltan(): void
    {
        $this->conPermisos();
        $sbp = $this->empresaSbp();
        $this->servicio()->resolver('Walmart');
        CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'WALMART']);
        CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'Shapiro']);
        CrmCliente::create(['empresa_id' => $sbp->id, 'nombre' => 'Vallarta']);

        $this->postJson(self::URL.'/configuracion/clientes/importar-crm')
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => '2 clientes agregados del CRM.', 'data' => ['agregados' => 2]]);
        $this->assertEqualsCanonicalizing(['Walmart', 'Shapiro', 'Vallarta'], PlanCliente::pluck('nombre')->all());

        $this->postJson(self::URL.'/configuracion/clientes/importar-crm')
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'No hay clientes nuevos en el CRM.', 'data' => ['agregados' => 0]]);
    }

    public function test_importar_un_solo_cliente_usa_singular(): void
    {
        $this->conPermisos();
        CrmCliente::create(['empresa_id' => $this->empresaSbp()->id, 'nombre' => 'Shapiro']);

        $this->postJson(self::URL.'/configuracion/clientes/importar-crm')
            ->assertOk()->assertJsonPath('message', '1 cliente agregado del CRM.');
    }

    public function test_importar_con_un_crm_sin_clientes(): void
    {
        $this->conPermisos();
        $this->empresaSbp();

        $this->postJson(self::URL.'/configuracion/clientes/importar-crm')
            ->assertOk()
            ->assertJsonPath('message', 'No hay clientes nuevos en el CRM.')
            ->assertJsonPath('data.agregados', 0);
    }

    // ── Permisos ────────────────────────────────────────

    public function test_sin_configuracion_editar_es_403_en_las_tres(): void
    {
        $this->conPermisos(['configuracion' => ['view'], 'necesidades-venta' => ['view', 'capturar']]);
        CrmCliente::create(['empresa_id' => $this->empresaSbp()->id, 'nombre' => 'Shapiro']);
        $walmart = $this->servicio()->resolver('Walmart');

        $this->postJson(self::URL.'/configuracion/clientes', ['nombre' => 'Nuevo'])->assertForbidden();
        $this->putJson(self::URL.'/configuracion/clientes/'.$walmart->id, ['nombre' => 'Otro', 'activo' => false])->assertForbidden();
        $this->postJson(self::URL.'/configuracion/clientes/importar-crm')->assertForbidden();

        $this->assertSame(['Walmart'], PlanCliente::pluck('nombre')->all());
        $this->assertTrue($walmart->fresh()->activo);
    }

    // ── Después de un renombrado ────────────────────────

    public function test_clientes_previos_traen_el_nombre_actual_del_cliente(): void
    {
        $this->conPermisos(['tablero-semanal' => ['view']]);
        $anterior = ProgramaSemanal::create(['lunes' => '2026-11-16']);
        $previa = $this->renglon('Walmart', 'Texas', 'Bulk', $anterior);
        // El renglón viejo conserva otra escritura; manda el nombre de la lista.
        PlanCliente::whereKey($previa->plan_cliente_id)->update(['nombre' => 'Walmart México', 'nombre_clave' => 'walmart mexico']);

        $previos = $this->getJson(self::URL.'/programa?lunes=2026-11-23')->assertOk()->json('data.clientes_previos');

        $this->assertSame([[
            'cliente_id' => null,
            'plan_cliente_id' => $previa->plan_cliente_id,
            'cliente_nombre' => 'Walmart México',
            'bodega_id' => $this->bodega('Texas')->id,
        ]], $previos);
    }

    public function test_volver_a_correr_la_carga_tras_renombrar_no_registra_el_nombre_viejo(): void
    {
        $a = $this->renglon('Walmart');
        $walmart = PlanCliente::firstOrFail();
        // Renombrado que dejó el texto viejo en la necesidad.
        $walmart->update(['nombre' => 'Walmart México', 'nombre_clave' => 'walmart mexico']);
        $sinLiga = $this->renglon('Caruso');
        DB::table('programa_necesidades')->where('id', $sinLiga->id)->update(['plan_cliente_id' => null]);
        PlanCliente::where('nombre', 'Caruso')->delete();

        (require base_path('database/migrations/'.self::MIGRACION))->up();

        $this->assertEqualsCanonicalizing(['Walmart México', 'Caruso'], PlanCliente::pluck('nombre')->all());
        $this->assertSame($walmart->id, $a->fresh()->plan_cliente_id);
        $this->assertSame(PlanCliente::where('nombre', 'Caruso')->value('id'), $sinLiga->fresh()->plan_cliente_id);
    }
}
