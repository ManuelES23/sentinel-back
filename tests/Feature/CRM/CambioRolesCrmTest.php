<?php

namespace Tests\Feature\CRM;

use App\Exceptions\CRM\RolesEquipoException;
use App\Models\ActivityLog;
use App\Models\Application;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmVendedor;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Models\UserEnterpriseAccess;
use App\Models\UserModuleAccess;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use App\Services\CRM\CambioRolesCrm;
use App\Services\CRM\CrmPerfilPermisosService;
use App\Services\CRM\DeduccionRolesCrm;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Guardar los roles del Equipo comercial (vende, gerencia, catálogos): se
 * suman, reemplazan los permisos del CRM de la empresa, manejan la ficha de
 * vendedor y son todo o nada. Spec 2026-11-01 §5 y §6.
 */
class CambioRolesCrmTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private CambioRolesCrm $cambio;
    private DeduccionRolesCrm $deduccion;
    private CrmPerfilPermisosService $perfiles;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        (new CrmPermisosSeeder())->run();

        $this->cambio = app(CambioRolesCrm::class);
        $this->deduccion = new DeduccionRolesCrm();
        $this->perfiles = new CrmPerfilPermisosService();

        $this->admin = $this->usuario();
        $this->admin->forceFill(['role' => 'admin'])->save();
    }

    // ── Auxiliares ────────────────────────────────────────────────────────

    private function usuario(?Enterprise $empresa = null, bool $acceso = true, array $atributos = []): User
    {
        $usuario = User::factory()->create($atributos);
        if ($acceso) {
            UserEnterpriseAccess::create([
                'user_id' => $usuario->id,
                'enterprise_id' => ($empresa ?? $this->enterprise)->id,
                'is_active' => true,
                'granted_at' => now(),
            ]);
        }

        return $usuario;
    }

    private function roles(bool $vende = false, bool $gerencia = false, bool $catalogos = false): array
    {
        return ['vende' => $vende, 'gerencia' => $gerencia, 'catalogos' => $catalogos];
    }

    /** Un cambio como lo manda la pantalla: lo que veía (antes) y lo que pide. */
    private function pedir(User $usuario, array $antes, array $roles): array
    {
        return ['user_id' => $usuario->id, 'antes' => $antes] + $roles;
    }

    /** Pone a la persona exactamente los roles dados (con su ficha si vende). */
    private function conRoles(User $usuario, array $roles, ?Enterprise $empresa = null): User
    {
        $empresa ??= $this->enterprise;
        $this->perfiles->aplicarRoles($usuario, $empresa, array_keys(array_filter($roles)));
        if ($roles['vende'] ?? false) {
            CrmVendedor::create([
                'empresa_id' => $empresa->id,
                'user_id' => $usuario->id,
                'nombre' => $usuario->name,
                'email' => $usuario->email,
                'activo' => true,
            ]);
        }

        return $usuario;
    }

    private function actual(User $usuario): array
    {
        return $this->deduccion->dePersona($usuario, $this->enterprise)['roles'];
    }

    /** Claves `modulo.submodulo.permiso` concedidas en el CRM de la empresa. */
    private function concedidos(User $usuario, ?Enterprise $empresa = null): array
    {
        $appId = Application::where('enterprise_id', ($empresa ?? $this->enterprise)->id)->where('slug', 'crm')->value('id');

        return DB::table('user_submodule_permissions as usp')
            ->join('submodule_permission_types as spt', 'spt.id', '=', 'usp.permission_type_id')
            ->join('submodules as s', 's.id', '=', 'usp.submodule_id')
            ->join('modules as m', 'm.id', '=', 's.module_id')
            ->where('m.application_id', $appId)
            ->where('usp.user_id', $usuario->id)
            ->where('usp.is_granted', true)
            ->select('m.slug as modulo', 's.slug as submodulo', 'spt.slug as permiso')
            ->get()
            ->map(fn ($f) => "{$f->modulo}.{$f->submodulo}.{$f->permiso}")
            ->sort()->values()->all();
    }

    private function otorgar(User $usuario, string $modulo, string $submodulo, array $permisos): void
    {
        $app = Application::where('enterprise_id', $this->enterprise->id)->where('slug', 'crm')->firstOrFail();
        $sub = Submodule::where('slug', $submodulo)
            ->whereHas('module', fn ($q) => $q->where('application_id', $app->id)->where('slug', $modulo))
            ->firstOrFail();

        // Con el acceso encendido en aplicación, módulo y submódulo: así el
        // permiso cuenta como real para la deducción.
        UserApplicationAccess::updateOrCreate(['user_id' => $usuario->id, 'application_id' => $app->id], ['is_active' => true, 'granted_at' => now()]);
        UserModuleAccess::updateOrCreate(['user_id' => $usuario->id, 'module_id' => $sub->module_id], ['is_active' => true, 'granted_at' => now()]);
        UserSubmoduleAccess::updateOrCreate(['user_id' => $usuario->id, 'submodule_id' => $sub->id], ['is_active' => true, 'granted_at' => now()]);

        foreach (SubmodulePermissionType::where('submodule_id', $sub->id)->whereIn('slug', $permisos)->get() as $tipo) {
            UserSubmodulePermission::updateOrCreate(
                ['user_id' => $usuario->id, 'submodule_id' => $sub->id, 'permission_type_id' => $tipo->id],
                ['is_granted' => true],
            );
        }
    }

    /** Quien captura sin ser Administrador, con permisos en catalogos.vendedores. */
    private function gestor(array $permisos): User
    {
        $actor = $this->usuario();
        $this->otorgar($actor, 'catalogos', 'vendedores', $permisos);

        return $actor;
    }

    /**
     * Quien tiene el rol Catálogos pero no es Administrador: sus permisos de
     * vendedores son limpios (no está «ajustado»), así que solo una regla
     * propia puede impedirle tocar su propia fila.
     */
    private function gestorConCatalogos(): User
    {
        $actor = $this->conRoles($this->usuario(), $this->roles(false, false, true));
        $this->assertFalse($this->deduccion->dePersona($actor, $this->enterprise)['ajustado']);

        return $actor;
    }

    private function fichasDe(User $usuario): int
    {
        return CrmVendedor::where('empresa_id', $this->enterprise->id)->where('user_id', $usuario->id)->count();
    }

    private function bitacora(): \Illuminate\Database\Eloquent\Collection
    {
        return ActivityLog::where('action', 'roles_crm')->get();
    }

    private function esperaRechazo(int $status, ?string $mensaje, callable $accion): RolesEquipoException
    {
        try {
            $accion();
        } catch (RolesEquipoException $e) {
            $this->assertSame($status, $e->status());
            if ($mensaje !== null) {
                $this->assertSame($mensaje, $e->getMessage());
            }

            return $e;
        }
        $this->fail('Se esperaba RolesEquipoException '.$status.'.');
    }

    private const SIN_ACCESO = 'El usuario no tiene acceso a esta empresa. Dale acceso a la empresa antes de aplicar un perfil del CRM.';

    // ── Unión de roles ────────────────────────────────────────────────────

    public function test_marcar_dos_roles_da_la_union(): void
    {
        $usuario = $this->usuario();

        $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(), $this->roles(false, true, true)),
        ]);

        $permisos = $this->concedidos($usuario);
        $this->assertContains('dashboard.dashboard.ejecutivo', $permisos);
        $this->assertContains('catalogos.regiones.crear', $permisos);
        $this->assertSame($this->roles(false, true, true), $this->actual($usuario));
        $this->assertFalse($this->deduccion->dePersona($usuario, $this->enterprise)['ajustado']);
    }

    public function test_quitar_un_rol_conserva_el_otro(): void
    {
        $usuario = $this->conRoles($this->usuario(), $this->roles(false, true, true));

        $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(false, true, true), $this->roles(false, true, false)),
        ]);

        $permisos = $this->concedidos($usuario);
        $this->assertContains('dashboard.dashboard.ejecutivo', $permisos);
        $this->assertNotContains('catalogos.regiones.crear', $permisos);
        $this->assertSame($this->roles(false, true, false), $this->actual($usuario));
    }

    public function test_no_toca_permisos_fuera_del_crm(): void
    {
        $usuario = $this->usuario();

        // Otra aplicación de la misma empresa.
        $otraApp = Application::create(['enterprise_id' => $this->enterprise->id, 'slug' => 'bodega', 'name' => 'Bodega', 'description' => 'Otra aplicación', 'path' => '/bodega', 'is_active' => true]);
        $modulo = Module::create(['application_id' => $otraApp->id, 'slug' => 'inventario', 'name' => 'Inventario', 'is_active' => true]);
        $sub = Submodule::create(['module_id' => $modulo->id, 'slug' => 'existencias', 'name' => 'Existencias', 'is_active' => true]);
        $tipo = SubmodulePermissionType::create(['submodule_id' => $sub->id, 'slug' => 'ver', 'name' => 'Ver', 'is_active' => true]);
        UserApplicationAccess::create(['user_id' => $usuario->id, 'application_id' => $otraApp->id, 'is_active' => true, 'granted_at' => now()]);
        UserModuleAccess::create(['user_id' => $usuario->id, 'module_id' => $modulo->id, 'is_active' => true, 'granted_at' => now()]);
        UserSubmoduleAccess::create(['user_id' => $usuario->id, 'submodule_id' => $sub->id, 'is_active' => true, 'granted_at' => now()]);
        UserSubmodulePermission::create(['user_id' => $usuario->id, 'submodule_id' => $sub->id, 'permission_type_id' => $tipo->id, 'is_granted' => true]);

        // El CRM de otra empresa, con el perfil gerencia.
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder())->run();
        UserEnterpriseAccess::create(['user_id' => $usuario->id, 'enterprise_id' => $otra->id, 'is_active' => true, 'granted_at' => now()]);
        $this->perfiles->aplicar($usuario, $otra, 'gerencia');
        $antesOtra = $this->concedidos($usuario, $otra);
        $this->assertNotEmpty($antesOtra);

        $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(), $this->roles(true, false, true)),
        ]);
        $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(true, false, true), $this->roles()),
        ]);

        $this->assertSame($antesOtra, $this->concedidos($usuario, $otra));
        $this->assertTrue(UserApplicationAccess::where('user_id', $usuario->id)
            ->where('application_id', Application::where('enterprise_id', $otra->id)->where('slug', 'crm')->value('id'))
            ->value('is_active'));
        $this->assertTrue((bool) UserSubmodulePermission::where('user_id', $usuario->id)->where('submodule_id', $sub->id)->value('is_granted'));
        $this->assertTrue((bool) UserApplicationAccess::where('user_id', $usuario->id)->where('application_id', $otraApp->id)->value('is_active'));
        $this->assertTrue((bool) UserModuleAccess::where('user_id', $usuario->id)->where('module_id', $modulo->id)->value('is_active'));
        $this->assertTrue((bool) UserSubmoduleAccess::where('user_id', $usuario->id)->where('submodule_id', $sub->id)->value('is_active'));
    }

    // ── Ficha de vendedor ─────────────────────────────────────────────────

    public function test_marcar_vende_crea_la_ficha_con_los_datos_del_usuario(): void
    {
        $usuario = $this->usuario(null, true, ['phone' => '6671234567']);

        $r = $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(), $this->roles(true)),
        ]);

        $this->assertCount(1, $r);
        $this->assertSame('created', $r[0]['accion']);
        $ficha = $r[0]['vendedor'];
        $this->assertInstanceOf(CrmVendedor::class, $ficha);
        $this->assertSame($usuario->name, $ficha->nombre);
        $this->assertSame($usuario->email, $ficha->email);
        $this->assertSame('6671234567', $ficha->telefono);
        $this->assertTrue($ficha->activo);
        $this->assertSame($this->enterprise->id, $ficha->empresa_id);
        $this->assertSame($usuario->id, $ficha->user_id);
        $this->assertTrue($ficha->relationLoaded('user'));
        $this->assertSame($usuario->name, $ficha->user->name);
        $this->assertSame($this->roles(true), $this->actual($usuario));
        $this->assertContains('oportunidades.oportunidades.ver', $this->concedidos($usuario));
    }

    public function test_marcar_vende_reactiva_la_ficha_existente(): void
    {
        $usuario = $this->usuario();
        $ficha = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => $usuario->id,
            'nombre' => 'Nombre de la ficha',
            'email' => 'ficha@example.com',
            'activo' => false,
        ]);

        $r = $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(), $this->roles(true)),
        ]);

        $this->assertSame('updated', $r[0]['accion']);
        $this->assertSame($ficha->id, $r[0]['vendedor']->id);
        $this->assertSame(1, $this->fichasDe($usuario));
        $this->assertTrue($ficha->fresh()->activo);
        $this->assertSame('Nombre de la ficha', $ficha->fresh()->nombre);
    }

    public function test_quitar_vende_desactiva_y_conserva_sus_oportunidades(): void
    {
        $usuario = $this->conRoles($this->usuario(), $this->roles(true));
        $ficha = CrmVendedor::where('user_id', $usuario->id)->firstOrFail();
        $oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $ficha->id,
            'nombre' => 'Trato abierto',
            'etapa' => 'prospecto',
        ]);

        $r = $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(true), $this->roles()),
        ]);

        $this->assertCount(1, $r);
        $this->assertSame('updated', $r[0]['accion']);
        $this->assertFalse($r[0]['vendedor']->activo);
        $this->assertFalse($ficha->fresh()->activo);
        $this->assertSame($ficha->id, $oportunidad->fresh()->vendedor_id);
        $this->assertSame(1, $this->fichasDe($usuario));
    }

    public function test_quitar_vende_a_gerente_conserva_los_permisos_base(): void
    {
        $usuario = $this->conRoles($this->usuario(), $this->roles(true, true));

        $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(true, true), $this->roles(false, true)),
        ]);

        $permisos = $this->concedidos($usuario);
        $this->assertContains('oportunidades.oportunidades.ver', $permisos);
        $this->assertContains('oportunidades.oportunidades.cerrar', $permisos);
        $this->assertSame($this->roles(false, true), $this->actual($usuario));
    }

    public function test_cambiar_gerencia_sin_tocar_vende_no_difunde_la_ficha(): void
    {
        $usuario = $this->conRoles($this->usuario(), $this->roles(true));

        $r = $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(true), $this->roles(true, true)),
        ]);

        $this->assertSame([], $r);
        $this->assertSame($this->roles(true, true), $this->actual($usuario));
    }

    public function test_sin_roles_apaga_la_aplicacion_y_no_la_empresa(): void
    {
        $usuario = $this->conRoles($this->usuario(), $this->roles(true, true, true));
        $appId = Application::where('enterprise_id', $this->enterprise->id)->where('slug', 'crm')->value('id');

        $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(true, true, true), $this->roles()),
        ]);

        $this->assertSame([], $this->concedidos($usuario));
        $this->assertFalse((bool) UserApplicationAccess::where('user_id', $usuario->id)->where('application_id', $appId)->value('is_active'));
        $this->assertFalse(UserModuleAccess::where('user_id', $usuario->id)->where('is_active', true)->exists());
        $this->assertFalse(UserSubmoduleAccess::where('user_id', $usuario->id)->where('is_active', true)->exists());
        $this->assertTrue(UserEnterpriseAccess::where('user_id', $usuario->id)
            ->where('enterprise_id', $this->enterprise->id)->where('is_active', true)->exists());
        $this->assertSame($this->roles(), $this->actual($usuario));
    }

    // ── Sin cambio / con cambio ───────────────────────────────────────────

    public function test_sin_cambio_no_escribe_aunque_este_ajustado(): void
    {
        $usuario = $this->conRoles($this->usuario(), $this->roles(true));
        $this->otorgar($usuario, 'catalogos', 'regiones', ['ver']);
        $antes = $this->concedidos($usuario);
        $this->assertTrue($this->deduccion->dePersona($usuario, $this->enterprise)['ajustado']);

        $r = $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(true), $this->roles(true)),
        ]);

        $this->assertSame([], $r);
        $this->assertSame($antes, $this->concedidos($usuario));
        $this->assertContains('catalogos.regiones.ver', $this->concedidos($usuario));
        $this->assertCount(0, $this->bitacora());
    }

    public function test_con_cambio_reemplaza_lo_ajustado(): void
    {
        $usuario = $this->conRoles($this->usuario(), $this->roles(true));
        $this->otorgar($usuario, 'catalogos', 'regiones', ['ver']);
        $this->assertTrue($this->deduccion->dePersona($usuario, $this->enterprise)['ajustado']);

        $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(true), $this->roles(true, true)),
        ]);

        $this->assertNotContains('catalogos.regiones.ver', $this->concedidos($usuario));
        $this->assertFalse($this->deduccion->dePersona($usuario, $this->enterprise)['ajustado']);
    }

    public function test_todo_o_nada(): void
    {
        $primero = $this->usuario();
        $sinAcceso = $this->usuario(null, false);

        $this->esperaRechazo(422, self::SIN_ACCESO, fn () => $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($primero, $this->roles(), $this->roles(true, true)),
            $this->pedir($sinAcceso, $this->roles(), $this->roles(false, true)),
        ]));

        $this->assertSame([], $this->concedidos($primero));
        $this->assertSame(0, $this->fichasDe($primero));
        $this->assertSame($this->roles(), $this->actual($primero));
        $this->assertCount(0, $this->bitacora());
        $this->assertFalse(UserApplicationAccess::where('user_id', $primero->id)->exists());
    }

    public function test_antes_distinto_responde_409_con_el_nombre(): void
    {
        $usuario = $this->conRoles($this->usuario(null, true, ['name' => 'Rosa Gerente']), $this->roles(false, true));
        $otro = $this->usuario();

        $this->esperaRechazo(409, 'Los roles de Rosa Gerente cambiaron; recarga la pantalla.', fn () => $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($otro, $this->roles(), $this->roles(true)),
            // La pantalla creía que no tenía ningún rol.
            $this->pedir($usuario, $this->roles(), $this->roles(false, true, true)),
        ]));

        $this->assertSame($this->roles(false, true), $this->actual($usuario));
        $this->assertSame(0, $this->fichasDe($otro));
    }

    // ── Acceso a la empresa ───────────────────────────────────────────────

    public function test_marcar_sin_acceso_a_la_empresa_responde_422(): void
    {
        $usuario = $this->usuario(null, false);

        $this->esperaRechazo(422, self::SIN_ACCESO, fn () => $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(), $this->roles(true)),
        ]));

        $this->assertSame(0, $this->fichasDe($usuario));
        $this->assertSame([], $this->concedidos($usuario));
    }

    public function test_quitar_a_quien_perdio_el_acceso_si_se_puede(): void
    {
        $usuario = $this->conRoles($this->usuario(), $this->roles(true, true));
        UserEnterpriseAccess::where('user_id', $usuario->id)->update(['is_active' => false]);

        $r = $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(true, true), $this->roles()),
        ]);

        $this->assertSame('updated', $r[0]['accion']);
        $this->assertSame($this->roles(), $this->actual($usuario));
        $this->assertSame([], $this->concedidos($usuario));
    }

    public function test_usuario_de_otra_empresa_responde_422_sin_escribir(): void
    {
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder())->run();
        $ajeno = $this->usuario($otra, true);

        $this->esperaRechazo(422, self::SIN_ACCESO, fn () => $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($ajeno, $this->roles(), $this->roles(false, true)),
        ]));

        $this->assertSame([], $this->concedidos($ajeno));
        $this->assertSame(0, $this->fichasDe($ajeno));

        // Si no pide nada, no hay nada que rechazar.
        $this->assertSame([], $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($ajeno, $this->roles(), $this->roles()),
        ]));
    }

    public function test_usuario_que_no_existe_o_repetido_responde_422(): void
    {
        $usuario = $this->usuario();

        $this->esperaRechazo(422, null, fn () => $this->cambio->guardar($this->enterprise, $this->admin, [
            ['user_id' => 999999, 'antes' => $this->roles()] + $this->roles(true),
        ]));
        $this->esperaRechazo(422, null, fn () => $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(), $this->roles(true)),
            $this->pedir($usuario, $this->roles(), $this->roles(false, true)),
        ]));

        $this->assertSame(0, $this->fichasDe($usuario));
    }

    // ── Quién puede qué ───────────────────────────────────────────────────

    public function test_con_permiso_de_vendedores_marca_vende_a_otro(): void
    {
        $actor = $this->gestor(['ver', 'crear']);
        $usuario = $this->usuario();

        $r = $this->cambio->guardar($this->enterprise, $actor, [
            $this->pedir($usuario, $this->roles(), $this->roles(true)),
        ]);

        $this->assertSame('created', $r[0]['accion']);
        $this->assertSame($this->roles(true), $this->actual($usuario));
    }

    public function test_con_permiso_de_editar_quita_vende_a_otro(): void
    {
        $actor = $this->gestor(['ver', 'editar']);
        $usuario = $this->conRoles($this->usuario(), $this->roles(true));

        $this->cambio->guardar($this->enterprise, $actor, [
            $this->pedir($usuario, $this->roles(true), $this->roles()),
        ]);

        $this->assertSame($this->roles(), $this->actual($usuario));
    }

    public function test_sin_crear_no_marca_vende(): void
    {
        $actor = $this->gestor(['ver', 'editar', 'eliminar']);
        $usuario = $this->usuario();

        $this->esperaRechazo(403, null, fn () => $this->cambio->guardar($this->enterprise, $actor, [
            $this->pedir($usuario, $this->roles(), $this->roles(true)),
        ]));

        $this->assertSame(0, $this->fichasDe($usuario));
        $this->assertSame([], $this->concedidos($usuario));
    }

    public function test_sin_editar_no_quita_vende(): void
    {
        $actor = $this->gestor(['ver', 'crear', 'eliminar']);
        $usuario = $this->conRoles($this->usuario(), $this->roles(true));

        $e = $this->esperaRechazo(403, null, fn () => $this->cambio->guardar($this->enterprise, $actor, [
            $this->pedir($usuario, $this->roles(true), $this->roles()),
        ]));

        $this->assertStringContainsString($usuario->name, $e->getMessage());
        $this->assertSame($this->roles(true), $this->actual($usuario));
        $this->assertTrue(CrmVendedor::where('user_id', $usuario->id)->value('activo') == 1);
    }

    public function test_sin_ser_admin_no_cambia_gerencia_ni_catalogos(): void
    {
        $actor = $this->gestor(['ver', 'crear', 'editar', 'eliminar']);
        $usuario = $this->usuario();

        foreach ([$this->roles(false, true), $this->roles(false, false, true), $this->roles(true, true)] as $pedido) {
            $this->esperaRechazo(403, null, fn () => $this->cambio->guardar($this->enterprise, $actor, [
                $this->pedir($usuario, $this->roles(), $pedido),
            ]));
        }

        // Tampoco quitárselos a quien ya los tiene.
        $gerente = $this->conRoles($this->usuario(), $this->roles(false, true, true));
        $this->esperaRechazo(403, null, fn () => $this->cambio->guardar($this->enterprise, $actor, [
            $this->pedir($gerente, $this->roles(false, true, true), $this->roles(false, true)),
        ]));

        $this->assertSame([], $this->concedidos($usuario));
        $this->assertSame(0, $this->fichasDe($usuario));
        $this->assertSame($this->roles(false, true, true), $this->actual($gerente));
    }

    public function test_sin_ser_admin_no_cambia_su_propia_fila(): void
    {
        $actor = $this->gestorConCatalogos();

        $e = $this->esperaRechazo(403, null, fn () => $this->cambio->guardar($this->enterprise, $actor, [
            $this->pedir($actor, $this->roles(false, false, true), $this->roles(true, false, true)),
        ]));

        $this->assertStringContainsString($actor->name, $e->getMessage());
        $this->assertSame(0, $this->fichasDe($actor));
    }

    public function test_sin_ser_admin_no_cambia_a_alguien_ajustado(): void
    {
        $actor = $this->gestor(['ver', 'crear', 'editar', 'eliminar']);
        $usuario = $this->conRoles($this->usuario(), $this->roles(true));
        $this->otorgar($usuario, 'catalogos', 'regiones', ['ver']);
        $this->assertTrue($this->deduccion->dePersona($usuario, $this->enterprise)['ajustado']);

        $e = $this->esperaRechazo(403, null, fn () => $this->cambio->guardar($this->enterprise, $actor, [
            $this->pedir($usuario, $this->roles(true), $this->roles()),
        ]));

        $this->assertStringContainsString($usuario->name, $e->getMessage());
        $this->assertContains('catalogos.regiones.ver', $this->concedidos($usuario));
        $this->assertTrue(CrmVendedor::where('user_id', $usuario->id)->value('activo') == 1);
    }

    public function test_sin_permisos_una_fila_sin_cambio_no_es_error(): void
    {
        $actor = $this->usuario();
        $usuario = $this->conRoles($this->usuario(), $this->roles(false, true));

        $r = $this->cambio->guardar($this->enterprise, $actor, [
            $this->pedir($usuario, $this->roles(false, true), $this->roles(false, true)),
            $this->pedir($actor, $this->roles(), $this->roles()),
        ]);

        $this->assertSame([], $r);
    }

    public function test_lote_con_una_fila_prohibida_no_escribe_ninguna(): void
    {
        $actor = $this->gestor(['ver', 'crear', 'editar', 'eliminar']);
        $valida = $this->usuario();
        $prohibida = $this->usuario();

        $this->esperaRechazo(403, null, fn () => $this->cambio->guardar($this->enterprise, $actor, [
            $this->pedir($valida, $this->roles(), $this->roles(true)),
            $this->pedir($prohibida, $this->roles(), $this->roles(false, true)),
        ]));

        $this->assertSame(0, $this->fichasDe($valida));
        $this->assertSame([], $this->concedidos($valida));
        $this->assertSame([], $this->concedidos($prohibida));
        $this->assertCount(0, $this->bitacora());
    }

    public function test_administrador_cambia_todo_sin_permiso_de_catalogos(): void
    {
        $usuario = $this->usuario();

        $this->assertSame([], $this->concedidos($this->admin));
        $r = $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(), $this->roles(true, true, true)),
        ]);
        $this->assertSame('created', $r[0]['accion']);
        $this->assertSame($this->roles(true, true, true), $this->actual($usuario));

        // Un administrador sí puede cambiar su propia fila y a un ajustado.
        $this->otorgar($usuario, 'catalogos', 'regiones', ['ver']);
        $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($this->admin, $this->roles(), $this->roles(false, false, true)),
            $this->pedir($usuario, $this->roles(true, true, true), $this->roles(true)),
        ]);
        $this->assertSame($this->roles(false, false, true), $this->actual($this->admin));
        $this->assertSame($this->roles(true), $this->actual($usuario));

        // Y el superadministrador, igual.
        $super = $this->usuario();
        $super->forceFill(['role' => 'superadmin'])->save();
        $otro = $this->usuario();
        $this->cambio->guardar($this->enterprise, $super, [
            $this->pedir($otro, $this->roles(), $this->roles(false, true)),
        ]);
        $this->assertSame($this->roles(false, true), $this->actual($otro));
    }

    // ── Ligar una ficha sin usuario ───────────────────────────────────────

    private function fichaSinUsuario(string $nombre = 'Ficha huérfana'): CrmVendedor
    {
        return CrmVendedor::create([
            'empresa_id' => $this->enterprise->id,
            'user_id' => null,
            'nombre' => $nombre,
            'email' => 'huerfana@example.com',
            'activo' => false,
        ]);
    }

    public function test_ligar_pone_el_usuario_y_suma_vende(): void
    {
        $gerente = $this->conRoles($this->usuario(), $this->roles(false, true));
        $ficha = $this->fichaSinUsuario();
        $oportunidad = CrmOportunidad::create([
            'empresa_id' => $this->enterprise->id,
            'vendedor_id' => $ficha->id,
            'nombre' => 'Trato heredado',
            'etapa' => 'prospecto',
        ]);

        $r = $this->cambio->ligar($this->enterprise, $this->admin, $ficha, $gerente);

        $this->assertCount(1, $r);
        $this->assertSame('updated', $r[0]['accion']);
        $this->assertSame($ficha->id, $r[0]['vendedor']->id);
        $this->assertTrue($r[0]['vendedor']->relationLoaded('user'));
        $this->assertSame($gerente->id, $r[0]['vendedor']->user->id);
        $fresca = $ficha->fresh();
        $this->assertSame($gerente->id, $fresca->user_id);
        $this->assertTrue($fresca->activo);
        $this->assertSame('Ficha huérfana', $fresca->nombre);
        $this->assertSame($ficha->id, $oportunidad->fresh()->vendedor_id);
        $this->assertSame($this->roles(true, true), $this->actual($gerente));
        $this->assertFalse($this->deduccion->dePersona($gerente, $this->enterprise)['ajustado']);
        $this->assertCount(1, $this->bitacora());
    }

    public function test_ligar_rechaza_a_quien_ya_tiene_ficha(): void
    {
        $usuario = $this->usuario();
        CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $usuario->id,
            'nombre' => 'Ya tenía', 'email' => 'ya@example.com', 'activo' => false,
        ]);
        $ficha = $this->fichaSinUsuario();

        $this->esperaRechazo(422, null, fn () => $this->cambio->ligar($this->enterprise, $this->admin, $ficha, $usuario));

        $this->assertNull($ficha->fresh()->user_id);
        $this->assertSame(1, $this->fichasDe($usuario));
        $this->assertSame([], $this->concedidos($usuario));
    }

    public function test_ligar_rechaza_ficha_con_usuario(): void
    {
        $dueno = $this->conRoles($this->usuario(), $this->roles(true));
        $ficha = CrmVendedor::where('user_id', $dueno->id)->firstOrFail();
        $otro = $this->usuario();

        $this->esperaRechazo(422, null, fn () => $this->cambio->ligar($this->enterprise, $this->admin, $ficha, $otro));

        $this->assertSame($dueno->id, $ficha->fresh()->user_id);
        $this->assertSame(0, $this->fichasDe($otro));
        $this->assertSame([], $this->concedidos($otro));
    }

    public function test_ligar_sin_acceso_a_la_empresa_responde_422(): void
    {
        $sinAcceso = $this->usuario(null, false);
        $ficha = $this->fichaSinUsuario();

        $this->esperaRechazo(422, self::SIN_ACCESO, fn () => $this->cambio->ligar($this->enterprise, $this->admin, $ficha, $sinAcceso));

        $this->assertNull($ficha->fresh()->user_id);
        $this->assertFalse($ficha->fresh()->activo);
        $this->assertSame([], $this->concedidos($sinAcceso));
    }

    public function test_ligar_exige_editar_y_no_su_propia_fila(): void
    {
        $usuario = $this->usuario();
        $ficha = $this->fichaSinUsuario();

        // Sin editar.
        $soloCrear = $this->gestor(['ver', 'crear', 'eliminar']);
        $this->esperaRechazo(403, null, fn () => $this->cambio->ligar($this->enterprise, $soloCrear, $ficha, $usuario));
        $this->assertNull($ficha->fresh()->user_id);

        // Con editar pero sobre su propia fila.
        $editor = $this->gestorConCatalogos();
        $this->esperaRechazo(403, null, fn () => $this->cambio->ligar($this->enterprise, $editor, $ficha, $editor));
        $this->assertNull($ficha->fresh()->user_id);
        $this->assertNotContains('oportunidades.oportunidades.ver', $this->concedidos($editor));

        // Con editar sobre otra persona sí.
        $r = $this->cambio->ligar($this->enterprise, $editor, $ficha, $usuario);
        $this->assertSame($usuario->id, $r[0]['vendedor']->user_id);
        $this->assertSame($this->roles(true), $this->actual($usuario));
    }

    public function test_ligar_sin_ser_admin_no_pisa_a_alguien_ajustado(): void
    {
        $editor = $this->gestor(['ver', 'editar']);
        $ajustado = $this->usuario();
        $this->otorgar($ajustado, 'catalogos', 'regiones', ['ver']);
        $ficha = $this->fichaSinUsuario();

        $this->assertTrue($this->deduccion->dePersona($ajustado, $this->enterprise)['ajustado']);
        $this->esperaRechazo(403, null, fn () => $this->cambio->ligar($this->enterprise, $editor, $ficha, $ajustado));

        $this->assertNull($ficha->fresh()->user_id);
        $this->assertContains('catalogos.regiones.ver', $this->concedidos($ajustado));
    }

    public function test_ligar_ficha_de_otra_empresa_responde_404(): void
    {
        $otra = $this->crearOtraEmpresa();
        $ficha = CrmVendedor::create([
            'empresa_id' => $otra->id, 'user_id' => null, 'nombre' => 'Ajena', 'email' => 'a@example.com', 'activo' => true,
        ]);
        $usuario = $this->usuario();

        $this->esperaRechazo(404, null, fn () => $this->cambio->ligar($this->enterprise, $this->admin, $ficha, $usuario));

        $this->assertNull($ficha->fresh()->user_id);
    }

    // ── Bitácora y compatibilidad ─────────────────────────────────────────

    public function test_deja_bitacora_con_roles_de_antes_y_despues(): void
    {
        $this->actingAs($this->admin);
        $usuario = $this->conRoles($this->usuario(), $this->roles(false, true));

        $this->cambio->guardar($this->enterprise, $this->admin, [
            $this->pedir($usuario, $this->roles(false, true), $this->roles(true, true, true)),
        ]);

        $registros = $this->bitacora();
        $this->assertCount(1, $registros);
        $registro = $registros->first();
        $this->assertSame('User', $registro->model);
        $this->assertSame($usuario->id, (int) $registro->model_id);
        $this->assertSame($this->admin->id, (int) $registro->user_id);
        $this->assertEquals(
            ['vende' => false, 'gerencia' => true, 'catalogos' => false, 'enterprise_id' => $this->enterprise->id],
            $registro->old_values,
        );
        $this->assertEquals(
            ['vende' => true, 'gerencia' => true, 'catalogos' => true, 'enterprise_id' => $this->enterprise->id],
            $registro->new_values,
        );
    }

    public function test_aplicar_perfil_viejo_sigue_reemplazando_y_no_toca_la_ficha(): void
    {
        $usuario = $this->conRoles($this->usuario(), $this->roles(true));
        $ficha = CrmVendedor::where('user_id', $usuario->id)->firstOrFail();

        $resumen = $this->perfiles->aplicar($usuario, $this->enterprise, 'administracion');

        $this->assertSame('administracion', $resumen['perfil']);
        $permisos = $this->concedidos($usuario);
        $this->assertContains('catalogos.regiones.crear', $permisos);
        $this->assertNotContains('oportunidades.oportunidades.ver', $permisos);
        $this->assertSame($resumen['permisos_otorgados'], count($permisos));
        $this->assertTrue((bool) UserApplicationAccess::where('user_id', $usuario->id)->value('is_active'));
        $this->assertSame(1, $this->fichasDe($usuario));
        $this->assertTrue($ficha->fresh()->activo);
    }

    // ── Qué puede hacer quien pregunta ────────────────────────────────────

    public function test_permisos_del_administrador_son_todos(): void
    {
        $this->assertSame([
            'user_id' => $this->admin->id,
            'roles_completos' => true,
            'marcar_vende' => true,
            'quitar_vende' => true,
            'editar_ficha' => true,
            'crear_ficha' => true,
            'eliminar_ficha' => true,
            'ver' => true,
        ], $this->cambio->permisos($this->enterprise, $this->admin));
    }

    public function test_permisos_de_quien_no_es_administrador_salen_de_vendedores(): void
    {
        $actor = $this->gestor(['ver', 'crear']);

        $this->assertSame([
            'user_id' => $actor->id,
            'roles_completos' => false,
            'marcar_vende' => true,
            'quitar_vende' => false,
            'editar_ficha' => false,
            'crear_ficha' => true,
            'eliminar_ficha' => false,
            'ver' => true,
        ], $this->cambio->permisos($this->enterprise, $actor));

        $editor = $this->gestor(['editar', 'eliminar']);
        $permisos = $this->cambio->permisos($this->enterprise, $editor);
        $this->assertTrue($permisos['quitar_vende']);
        $this->assertTrue($permisos['editar_ficha']);
        $this->assertTrue($permisos['eliminar_ficha']);
        $this->assertFalse($permisos['marcar_vende']);
        $this->assertFalse($permisos['ver']);

        $nada = $this->cambio->permisos($this->enterprise, $this->usuario());
        $this->assertSame([false], array_values(array_unique(array_slice($nada, 1))));
    }
}
