<?php

namespace Tests\Feature\SplendidFarms\Inventory;

use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\UserEntityAccess;
use App\Services\Inventory\AlmacenAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAlmacenFixtures;
use Tests\TestCase;

class AlmacenAccessServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAlmacenFixtures;

    private AlmacenAccessService $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAlmacenFixtures();
        $this->servicio = app(AlmacenAccessService::class);
    }

    public function test_usuario_solo_ve_sus_almacenes_asignados(): void
    {
        $user = $this->crearUsuarioDeCampo([$this->almacenA]);

        $this->assertSame([$this->almacenA->id], $this->servicio->idsVisibles($user, $this->empresa));
        $this->assertTrue($this->servicio->puedeVer($user, $this->empresa, $this->almacenA->id));
        $this->assertFalse($this->servicio->puedeVer($user, $this->empresa, $this->almacenB->id));
    }

    public function test_usuario_sin_asignaciones_no_ve_nada(): void
    {
        $user = $this->crearUsuarioDeCampo();

        $this->assertSame([], $this->servicio->idsVisibles($user, $this->empresa));
    }

    public function test_permiso_ver_todos_y_admin_ven_todos(): void
    {
        $user = $this->crearUsuarioDeCampo();
        $this->otorgarVerTodos($user);
        $esperado = [$this->almacenA->id, $this->almacenB->id];

        $ids = $this->servicio->idsVisibles($user, $this->empresa);
        sort($ids);
        $this->assertSame($esperado, $ids);

        $ids = $this->servicio->idsVisibles($this->crearAdmin(), $this->empresa);
        sort($ids);
        $this->assertSame($esperado, $ids);
    }

    public function test_asignacion_de_almacen_de_otra_empresa_no_cuenta(): void
    {
        $otra = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'x']);
        $user = $this->crearUsuarioDeCampo([$this->almacenA]);

        $this->assertSame([], $this->servicio->idsVisibles($user, $otra));
    }

    public function test_resolver_empresa_sin_header_da_422_y_sin_membresia_da_403(): void
    {
        $user = $this->crearUsuarioDeCampo();

        $sinHeader = Request::create('/x');
        $sinHeader->setUserResolver(fn () => $user);
        try {
            $this->servicio->resolverEmpresa($sinHeader);
            $this->fail('Debió lanzar excepción');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
        }

        Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'x']);
        $ajena = Request::create('/x', 'GET', [], [], [], ['HTTP_X_ENTERPRISE_SLUG' => 'canes-agro']);
        $ajena->setUserResolver(fn () => $user);
        try {
            $this->servicio->resolverEmpresa($ajena);
            $this->fail('Debió lanzar excepción');
        } catch (HttpResponseException $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
        }

        $propia = Request::create('/x', 'GET', [], [], [], ['HTTP_X_ENTERPRISE_SLUG' => 'splendidfarms']);
        $propia->setUserResolver(fn () => $user);
        $this->assertSame($this->empresa->id, $this->servicio->resolverEmpresa($propia)->id);
    }

    public function test_entidades_vinculadas_via_enterprise_entity(): void
    {
        // Crear segunda empresa con su rama y entidad
        $otra = Enterprise::create(['name' => 'Canes Agro', 'slug' => 'canes-agro', 'is_active' => true, 'description' => 'Empresa de caña']);
        $sucursalOtra = Branch::create([
            'enterprise_id' => $otra->id,
            'code' => 'SUC-02',
            'name' => 'Central Caña',
            'slug' => 'central-cana',
        ]);
        $almacenCana = Entity::create([
            'branch_id' => $sucursalOtra->id,
            'entity_type_id' => $this->tipoCampo->id,
            'code' => 'ALM-CANA',
            'name' => 'Almacén Caña',
        ]);

        // Vincularlo a Splendid Farms (el almacén ya está en enterprise_entity para Canes Agro por el migration)
        DB::table('enterprise_entity')->insert([
            'enterprise_id' => $this->empresa->id,
            'entity_id' => $almacenCana->id,
            'access_level' => 'read',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // (a) idsDeEmpresa debe incluir la entidad vinculada
        $ids = $this->servicio->idsDeEmpresa($this->empresa);
        sort($ids);
        $esperado = [$this->almacenA->id, $this->almacenB->id, $almacenCana->id];
        sort($esperado);
        $this->assertSame($esperado, $ids);

        // (b) usuario con otorgarVerTodos ve la entidad vinculada
        $userConVerTodos = $this->crearUsuarioDeCampo();
        $this->otorgarVerTodos($userConVerTodos);
        $ids = $this->servicio->idsVisibles($userConVerTodos, $this->empresa);
        sort($ids);
        $this->assertSame($esperado, $ids);

        // (c) usuario asignado a la entidad vinculada la ve
        $userAsignado = $this->crearUsuarioDeCampo([$almacenCana]);
        $this->assertSame([$almacenCana->id], $this->servicio->idsVisibles($userAsignado, $this->empresa));
        $this->assertTrue($this->servicio->puedeVer($userAsignado, $this->empresa, $almacenCana->id));

        // (d) usuario sin asignación a la entidad vinculada no la ve
        $userSinAsignacion = $this->crearUsuarioDeCampo();
        $this->assertFalse($this->servicio->puedeVer($userSinAsignacion, $this->empresa, $almacenCana->id));
    }
}
