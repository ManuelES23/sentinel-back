<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\VendedorUpdated;
use App\Http\Controllers\Api\CRM\EquipoController;
use App\Http\Controllers\Api\CRM\PerfilPermisosController;
use App\Models\ActivityLog;
use App\Models\Application;
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
use App\Services\CRM\CrmPerfilPermisosService;
use App\Services\CRM\DeduccionRolesCrm;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * API de Equipo comercial (crm/equipo) y de los roles en el panel de
 * administración (crm/perfiles/...). Spec 2026-11-01 §6, §7 y §10.
 */
class EquipoApiTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private const MIGRACION = 'migrations/2026_11_01_100000_rename_crm_vendedores_submodule.php';

    private CrmPerfilPermisosService $perfiles;
    private DeduccionRolesCrm $deduccion;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        (new CrmPermisosSeeder())->run();

        $this->perfiles = new CrmPerfilPermisosService();
        $this->deduccion = new DeduccionRolesCrm();

        $this->admin = $this->usuario();
        $this->admin->forceFill(['role' => 'admin'])->save();
    }

    // ── Auxiliares ────────────────────────────────────────────────────────

    private function usuario(?Enterprise $empresa = null, bool $acceso = true): User
    {
        $usuario = User::factory()->create();
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

    private function otorgar(User $usuario, string $modulo, string $submodulo, array $permisos): void
    {
        $app = Application::where('enterprise_id', $this->enterprise->id)->where('slug', 'crm')->firstOrFail();
        $sub = Submodule::where('slug', $submodulo)
            ->whereHas('module', fn ($q) => $q->where('application_id', $app->id)->where('slug', $modulo))
            ->firstOrFail();

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

    private function como(User $actor): void
    {
        Sanctum::actingAs($actor);
    }

    private function actual(User $usuario): array
    {
        return $this->deduccion->dePersona($usuario, $this->enterprise)['roles'];
    }

    private function fichasDe(User $usuario): int
    {
        return CrmVendedor::where('empresa_id', $this->enterprise->id)->where('user_id', $usuario->id)->count();
    }

    private function persona(array $respuesta, User $usuario): ?array
    {
        return collect($respuesta['data']['personas'])->firstWhere('user_id', $usuario->id);
    }

    private function equipo(?int $empresaId = null)
    {
        return $this->getJson('/api/crm/equipo', $this->crmHeaders($empresaId));
    }

    private function guardar(array $cambios, ?int $empresaId = null)
    {
        return $this->putJson('/api/crm/equipo', ['cambios' => $cambios], $this->crmHeaders($empresaId));
    }

    // ── GET crm/equipo ────────────────────────────────────────────────────

    public function test_lista_el_equipo_con_permisos_de_quien_pregunta(): void
    {
        $gerente = $this->conRoles($this->usuario(), $this->roles(true, true, false));
        $libre = $this->usuario();
        $this->como($this->admin);

        $respuesta = $this->equipo()->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['personas', 'usuarios_disponibles', 'permisos']]);

        $fila = $this->persona($respuesta->json(), $gerente);
        $this->assertSame($this->roles(true, true, false), $fila['roles']);
        $this->assertSame($this->vendedor->id, collect($respuesta->json('data.personas'))->firstWhere('user_id', $this->vendedor->user_id)['vendedor_id']);
        $this->assertContains($libre->id, array_column($respuesta->json('data.usuarios_disponibles'), 'id'));

        $permisos = $respuesta->json('data.permisos');
        $this->assertSame($this->admin->id, $permisos['user_id']);
        $this->assertTrue($permisos['roles_completos']);
        $this->assertTrue($permisos['marcar_vende']);
        $this->assertTrue($permisos['ver']);
    }

    public function test_sin_ver_y_sin_ser_admin_responde_403(): void
    {
        $this->como($this->usuario());
        $this->equipo()->assertForbidden();

        // Crear y editar sin poder ver tampoco alcanza.
        $this->como($this->gestor(['crear', 'editar', 'eliminar']));
        $this->equipo()->assertForbidden();
    }

    public function test_administrador_sin_permiso_de_catalogos_entra(): void
    {
        // El rol del sistema alcanza, sin un solo permiso de Catálogos.
        $this->como($this->admin);
        $this->equipo()->assertOk()->assertJsonPath('data.permisos.roles_completos', true);

        $super = $this->usuario();
        $super->forceFill(['role' => 'superadmin'])->save();
        $this->como($super);
        $this->equipo()->assertOk()->assertJsonPath('data.permisos.roles_completos', true);
    }

    public function test_sin_marcar_vende_no_trae_usuarios_disponibles(): void
    {
        $libre = $this->usuario();

        $this->como($this->gestor(['ver']));
        $this->equipo()->assertOk()
            ->assertJsonPath('data.usuarios_disponibles', [])
            ->assertJsonPath('data.permisos.marcar_vende', false)
            ->assertJsonPath('data.permisos.roles_completos', false);

        $this->como($this->gestor(['ver', 'crear']));
        $respuesta = $this->equipo()->assertOk()->assertJsonPath('data.permisos.marcar_vende', true);
        $this->assertContains($libre->id, array_column($respuesta->json('data.usuarios_disponibles'), 'id'));
    }

    public function test_el_encabezado_de_otra_empresa_sin_acceso_responde_403(): void
    {
        $otra = $this->crearOtraEmpresa();
        $this->como($this->admin);

        // El actor no tiene acceso activo a la otra empresa: no se confía en el encabezado.
        $this->equipo($otra->id)->assertForbidden();
        $this->guardar([$this->pedir($this->usuario(), $this->roles(), $this->roles(true))], $otra->id)->assertForbidden();
    }

    public function test_el_equipo_no_mezcla_empresas(): void
    {
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder())->run();
        $ajeno = $this->usuario($otra);
        UserEnterpriseAccess::create(['user_id' => $this->admin->id, 'enterprise_id' => $otra->id, 'is_active' => true, 'granted_at' => now()]);
        $this->conRoles($ajeno, $this->roles(true, true), $otra);

        $this->como($this->admin);
        $this->assertNull($this->persona($this->equipo()->assertOk()->json(), $ajeno));
        $this->assertNotNull($this->persona($this->equipo($otra->id)->assertOk()->json(), $ajeno));
    }

    public function test_empresa_sin_crm_responde_422_en_el_equipo(): void
    {
        $sinCrm = $this->crearOtraEmpresa();
        UserEnterpriseAccess::create(['user_id' => $this->admin->id, 'enterprise_id' => $sinCrm->id, 'is_active' => true, 'granted_at' => now()]);

        $this->como($this->admin);
        $this->equipo($sinCrm->id)->assertStatus(422)
            ->assertJsonPath('message', 'La empresa no tiene el CRM Comercial configurado.');
        $this->guardar([$this->pedir($this->usuario(), $this->roles(), $this->roles(true))], $sinCrm->id)->assertStatus(422)
            ->assertJsonPath('message', 'La empresa no tiene el CRM Comercial configurado.');
    }

    // ── PUT crm/equipo ────────────────────────────────────────────────────

    public function test_put_valida_el_cuerpo(): void
    {
        $this->como($this->admin);
        $u = $this->usuario();
        $valido = $this->pedir($u, $this->roles(), $this->roles(true));

        $casos = [
            'sin cambios' => ['cambios' => []],
            'sin la llave cambios' => [],
            'cambios no es lista' => ['cambios' => 'x'],
            'más de 50' => ['cambios' => array_map(
                fn () => $this->pedir($this->usuario(), $this->roles(), $this->roles(true)), range(1, 51)
            )],
            'user_id repetido' => ['cambios' => [$valido, $valido]],
            'user_id inexistente' => ['cambios' => [['user_id' => 999999] + $valido]],
            'sin user_id' => ['cambios' => [array_diff_key($valido, ['user_id' => 1])]],
            'sin antes' => ['cambios' => [array_diff_key($valido, ['antes' => 1])]],
            'antes sin un booleano' => ['cambios' => [['antes' => ['vende' => false, 'gerencia' => false]] + $valido]],
            'antes no booleano' => ['cambios' => [['antes' => ['vende' => 'tal', 'gerencia' => false, 'catalogos' => false]] + $valido]],
            'falta vende' => ['cambios' => [array_diff_key($valido, ['vende' => 1])]],
            'falta gerencia' => ['cambios' => [array_diff_key($valido, ['gerencia' => 1])]],
            'falta catalogos' => ['cambios' => [array_diff_key($valido, ['catalogos' => 1])]],
        ];

        foreach ($casos as $nombre => $cuerpo) {
            $this->putJson('/api/crm/equipo', $cuerpo, $this->crmHeaders())
                ->assertStatus(422, "Caso: {$nombre}");
        }

        $this->assertSame(0, $this->fichasDe($u));
        $this->assertSame(0, ActivityLog::where('action', 'roles_crm')->count());
    }

    public function test_put_guarda_y_difunde_despues_de_confirmar(): void
    {
        Event::fake([VendedorUpdated::class]);
        $nuevo = $this->usuario();
        $this->como($this->admin);

        // Vende nuevo: crea la ficha y se despacha una sola vez, con «created».
        $respuesta = $this->guardar([$this->pedir($nuevo, $this->roles(), $this->roles(true))])
            ->assertOk()->assertJsonStructure(['data' => ['personas', 'usuarios_disponibles', 'permisos']]);

        $this->assertSame($this->roles(true), $this->persona($respuesta->json(), $nuevo)['roles']);
        $this->assertSame(1, $this->fichasDe($nuevo));
        Event::assertDispatchedTimes(VendedorUpdated::class, 1);
        Event::assertDispatched(VendedorUpdated::class, fn ($e) => $e->action === 'created'
            && $e->data['user_id'] === $nuevo->id && $e->data['empresa_id'] === $this->enterprise->id);

        // Quitarle Vende desactiva la ficha: otro «updated».
        $this->guardar([$this->pedir($nuevo, $this->roles(true), $this->roles())])->assertOk();
        Event::assertDispatchedTimes(VendedorUpdated::class, 2);
        Event::assertDispatched(VendedorUpdated::class, fn ($e) => $e->action === 'updated');

        // Sin cambio de roles: no se escribe ni se difunde.
        $this->guardar([$this->pedir($nuevo, $this->roles(), $this->roles())])->assertOk();
        Event::assertDispatchedTimes(VendedorUpdated::class, 2);
    }

    public function test_put_rechazado_no_difunde_ni_escribe(): void
    {
        Event::fake([VendedorUpdated::class]);
        $objetivo = $this->usuario();
        $otro = $this->usuario();

        // 403: un gestor con ver/crear/editar no puede cambiar Gerencia, aunque en el lote
        // vaya antes una fila válida (Vende), que tampoco debe quedar escrita.
        $this->como($this->gestor(['ver', 'crear', 'editar']));
        $this->guardar([
            $this->pedir($otro, $this->roles(), $this->roles(true)),
            $this->pedir($objetivo, $this->roles(), $this->roles(false, true)),
        ])->assertForbidden();
        $this->assertSame(0, $this->fichasDe($otro));
        $this->assertSame($this->roles(), $this->actual($objetivo));

        // 409: la pantalla veía otra cosa.
        $this->como($this->admin);
        $this->guardar([$this->pedir($objetivo, $this->roles(true), $this->roles(false, true))])
            ->assertStatus(409);

        // 422: sin acceso a la empresa.
        $sinAcceso = $this->usuario(null, false);
        $this->guardar([$this->pedir($sinAcceso, $this->roles(), $this->roles(true))])->assertStatus(422);

        Event::assertNotDispatched(VendedorUpdated::class);
        $this->assertSame(0, ActivityLog::where('action', 'roles_crm')->count());
    }

    public function test_put_sin_ver_responde_403_aunque_pueda_crear(): void
    {
        Event::fake([VendedorUpdated::class]);
        $libre = $this->usuario();
        $this->como($this->gestor(['crear', 'editar']));

        $this->guardar([$this->pedir($libre, $this->roles(), $this->roles(true))])->assertForbidden();

        $this->assertSame(0, $this->fichasDe($libre));
        Event::assertNotDispatched(VendedorUpdated::class);
    }

    public function test_gestor_con_crear_marca_vende_y_recibe_la_lista(): void
    {
        $libre = $this->usuario();
        $this->como($this->gestor(['ver', 'crear']));

        $respuesta = $this->guardar([$this->pedir($libre, $this->roles(), $this->roles(true))])->assertOk();

        $this->assertSame($this->roles(true), $this->persona($respuesta->json(), $libre)['roles']);
        $this->assertFalse($respuesta->json('data.permisos.roles_completos'));
    }

    // ── POST crm/equipo/fichas/{vendedor}/ligar ───────────────────────────

    public function test_ligar_una_ficha_sin_usuario_difunde_y_devuelve_la_lista(): void
    {
        Event::fake([VendedorUpdated::class]);
        $ficha = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Sin usuario', 'activo' => true]);
        $usuario = $this->usuario();
        $this->como($this->admin);

        $respuesta = $this->postJson("/api/crm/equipo/fichas/{$ficha->id}/ligar", ['user_id' => $usuario->id], $this->crmHeaders())
            ->assertOk()->assertJsonStructure(['data' => ['personas', 'usuarios_disponibles', 'permisos']]);

        $this->assertSame($usuario->id, $ficha->fresh()->user_id);
        $this->assertSame($this->roles(true), $this->persona($respuesta->json(), $usuario)['roles']);
        Event::assertDispatchedTimes(VendedorUpdated::class, 1);
        Event::assertDispatched(VendedorUpdated::class, fn ($e) => $e->action === 'updated' && $e->data['id'] === $ficha->id);
    }

    public function test_ligar_ficha_de_otra_empresa_responde_404(): void
    {
        Event::fake([VendedorUpdated::class]);
        $otra = $this->crearOtraEmpresa();
        $ajena = CrmVendedor::create(['empresa_id' => $otra->id, 'nombre' => 'Ajena', 'activo' => true]);
        $usuario = $this->usuario();
        $this->como($this->admin);

        $this->postJson("/api/crm/equipo/fichas/{$ajena->id}/ligar", ['user_id' => $usuario->id], $this->crmHeaders())
            ->assertNotFound();
        $this->postJson('/api/crm/equipo/fichas/9999999/ligar', ['user_id' => $usuario->id], $this->crmHeaders())
            ->assertNotFound();

        $this->assertNull($ajena->fresh()->user_id);
        Event::assertNotDispatched(VendedorUpdated::class);
    }

    public function test_ligar_valida_y_respeta_los_permisos_sin_difundir(): void
    {
        Event::fake([VendedorUpdated::class]);
        $ficha = CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Sin usuario', 'activo' => true]);
        $usuario = $this->usuario();

        $this->como($this->admin);
        $this->postJson("/api/crm/equipo/fichas/{$ficha->id}/ligar", [], $this->crmHeaders())->assertStatus(422);
        $this->postJson("/api/crm/equipo/fichas/{$ficha->id}/ligar", ['user_id' => 999999], $this->crmHeaders())->assertStatus(422);

        // Solo ver: no puede ligar.
        $this->como($this->gestor(['ver']));
        $this->postJson("/api/crm/equipo/fichas/{$ficha->id}/ligar", ['user_id' => $usuario->id], $this->crmHeaders())->assertForbidden();

        // Sin ver: ni siquiera entra.
        $this->como($this->usuario());
        $this->postJson("/api/crm/equipo/fichas/{$ficha->id}/ligar", ['user_id' => $usuario->id], $this->crmHeaders())->assertForbidden();

        $this->assertNull($ficha->fresh()->user_id);
        Event::assertNotDispatched(VendedorUpdated::class);
    }

    // ── Panel admin: crm/perfiles/usuarios/{user}/roles ───────────────────

    public function test_panel_admin_lee_y_guarda_con_enterprise_id(): void
    {
        Event::fake([VendedorUpdated::class]);
        $usuario = $this->usuario();
        $this->como($this->admin);

        $this->getJson("/api/crm/perfiles/usuarios/{$usuario->id}/roles?enterprise_id={$this->enterprise->id}")
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Operación exitosa', 'data' => [
                'roles' => $this->roles(), 'ajustado' => false, 'aviso' => null, 'oportunidades_abiertas' => 0,
            ]]);

        $this->putJson("/api/crm/perfiles/usuarios/{$usuario->id}/roles", [
            'enterprise_id' => $this->enterprise->id,
            'antes' => $this->roles(),
            'vende' => true, 'gerencia' => false, 'catalogos' => true,
        ])->assertOk()
            ->assertJsonPath('data.roles', $this->roles(true, false, true))
            ->assertJsonPath('data.ajustado', false)
            ->assertJsonStructure(['data' => ['roles', 'ajustado', 'aviso', 'oportunidades_abiertas']]);

        $this->assertSame($this->roles(true, false, true), $this->actual($usuario));
        $this->assertSame(1, $this->fichasDe($usuario));
        Event::assertDispatchedTimes(VendedorUpdated::class, 1);

        // Una lectura vieja ya no coincide: 409 y no difunde de nuevo.
        $this->putJson("/api/crm/perfiles/usuarios/{$usuario->id}/roles", [
            'enterprise_id' => $this->enterprise->id,
            'antes' => $this->roles(),
            'vende' => false, 'gerencia' => true, 'catalogos' => false,
        ])->assertStatus(409);
        Event::assertDispatchedTimes(VendedorUpdated::class, 1);
    }

    public function test_panel_admin_valida_el_cuerpo(): void
    {
        $usuario = $this->usuario();
        $this->como($this->admin);
        $ruta = "/api/crm/perfiles/usuarios/{$usuario->id}/roles";
        $valido = ['enterprise_id' => $this->enterprise->id, 'antes' => $this->roles(), 'vende' => true, 'gerencia' => false, 'catalogos' => false];

        $this->getJson($ruta)->assertStatus(422);
        $this->getJson("{$ruta}?enterprise_id=999999")->assertStatus(422);
        foreach (['enterprise_id', 'antes', 'vende', 'gerencia', 'catalogos'] as $campo) {
            $this->putJson($ruta, array_diff_key($valido, [$campo => 1]))->assertStatus(422);
        }
        $this->putJson($ruta, ['antes' => ['vende' => false]] + $valido)->assertStatus(422);
        $this->putJson($ruta, ['enterprise_id' => 999999] + $valido)->assertStatus(422);

        $this->assertSame(0, $this->fichasDe($usuario));
    }

    public function test_panel_admin_solo_administradores(): void
    {
        Event::fake([VendedorUpdated::class]);
        $usuario = $this->usuario();
        $ruta = "/api/crm/perfiles/usuarios/{$usuario->id}/roles";
        $cuerpo = ['enterprise_id' => $this->enterprise->id, 'antes' => $this->roles(), 'vende' => true, 'gerencia' => false, 'catalogos' => false];

        // Aunque tenga todos los permisos del CRM, sin el rol del sistema no entra.
        $this->otorgarTodosLosPermisosCrm();
        $this->como($this->actingUser);

        $this->getJson("{$ruta}?enterprise_id={$this->enterprise->id}")->assertForbidden();
        $this->putJson($ruta, $cuerpo)->assertForbidden();
        $this->getJson('/api/crm/perfiles/resumen')->assertForbidden();

        $this->assertSame(0, $this->fichasDe($usuario));
        Event::assertNotDispatched(VendedorUpdated::class);
    }

    public function test_panel_admin_empresa_sin_crm_responde_422(): void
    {
        $sinCrm = $this->crearOtraEmpresa();
        $usuario = $this->usuario();
        $this->como($this->admin);
        $ruta = "/api/crm/perfiles/usuarios/{$usuario->id}/roles";

        $this->getJson("{$ruta}?enterprise_id={$sinCrm->id}")->assertStatus(422)
            ->assertJsonPath('message', 'La empresa no tiene el CRM Comercial configurado.');
        $this->putJson($ruta, ['enterprise_id' => $sinCrm->id, 'antes' => $this->roles(), 'vende' => true, 'gerencia' => false, 'catalogos' => false])
            ->assertStatus(422)
            ->assertJsonPath('message', 'La empresa no tiene el CRM Comercial configurado.');
    }

    public function test_panel_admin_sin_acceso_a_la_empresa_responde_422_al_asignar(): void
    {
        $sinAcceso = $this->usuario(null, false);
        $this->como($this->admin);

        $this->putJson("/api/crm/perfiles/usuarios/{$sinAcceso->id}/roles", [
            'enterprise_id' => $this->enterprise->id, 'antes' => $this->roles(),
            'vende' => false, 'gerencia' => true, 'catalogos' => false,
        ])->assertStatus(422);
    }

    // ── GET crm/perfiles/resumen ──────────────────────────────────────────

    public function test_resumen_agrupa_por_usuario_y_no_trae_empresas_vacias(): void
    {
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder())->run();
        $sinCrm = $this->crearOtraEmpresa();

        // U1: Vende+Gerencia aquí y Catálogos en la otra. U2: solo en la otra. U3: sin nada.
        $u1 = $this->conRoles($this->usuario(), $this->roles(true, true));
        UserEnterpriseAccess::create(['user_id' => $u1->id, 'enterprise_id' => $otra->id, 'is_active' => true, 'granted_at' => now()]);
        $this->conRoles($u1, $this->roles(false, false, true), $otra);
        $u2 = $this->conRoles($this->usuario($otra), $this->roles(true), $otra);
        $u3 = $this->usuario();

        // Ajustado a mano: un permiso suelto sin ningún rol.
        $u4 = $this->usuario();
        $this->otorgar($u4, 'catalogos', 'zonas', ['ver']);

        // Ficha sin usuario: no es una persona del resumen.
        CrmVendedor::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Huérfana', 'activo' => true]);

        $this->como($this->admin);
        $filas = collect($this->getJson('/api/crm/perfiles/resumen')->assertOk()->json('data'))->keyBy('user_id');

        $this->assertNull($filas->get($u3->id));
        $this->assertNull($filas->get($this->admin->id));
        $this->assertNotContains(null, $filas->keys()->all());

        $f1 = collect($filas[$u1->id]['empresas'])->keyBy('enterprise_id');
        $this->assertCount(2, $f1);
        $this->assertSame(
            ['enterprise_id' => $this->enterprise->id, 'nombre' => $this->enterprise->name, 'vende' => true, 'gerencia' => true, 'catalogos' => false, 'ajustado' => false],
            $f1[$this->enterprise->id],
        );
        $this->assertSame(
            ['enterprise_id' => $otra->id, 'nombre' => $otra->name, 'vende' => false, 'gerencia' => false, 'catalogos' => true, 'ajustado' => false],
            $f1[$otra->id],
        );

        $f2 = $filas[$u2->id]['empresas'];
        $this->assertCount(1, $f2);
        $this->assertSame($otra->id, $f2[0]['enterprise_id']);

        $f4 = $filas[$u4->id]['empresas'];
        $this->assertCount(1, $f4);
        $this->assertSame(['vende' => false, 'gerencia' => false, 'catalogos' => false, 'ajustado' => true],
            array_intersect_key($f4[0], array_flip(['vende', 'gerencia', 'catalogos', 'ajustado'])));

        $this->assertNotContains($sinCrm->id, collect($filas)->pluck('empresas')->flatten(1)->pluck('enterprise_id')->all());
    }

    public function test_resumen_usa_un_numero_constante_de_consultas_por_empresa(): void
    {
        $this->conRoles($this->usuario(), $this->roles(true));
        $this->como($this->admin);

        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/crm/perfiles/resumen')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $con1 = $contar();
        foreach (range(1, 8) as $_) {
            $this->conRoles($this->usuario(), $this->roles(true, true));
        }
        $con9 = $contar();

        $this->assertSame($con1, $con9, 'El resumen no debe consultar por persona.');
    }

    // ── Rutas ─────────────────────────────────────────────────────────────

    public function test_las_rutas_nuevas_llegan_a_sus_metodos(): void
    {
        $esperadas = [
            ['GET', 'api/crm/equipo', EquipoController::class.'@index'],
            ['PUT', 'api/crm/equipo', EquipoController::class.'@guardar'],
            ['POST', 'api/crm/equipo/fichas/5/ligar', EquipoController::class.'@ligar'],
            ['GET', 'api/crm/perfiles/resumen', PerfilPermisosController::class.'@resumen'],
            ['GET', 'api/crm/perfiles/usuarios/7/roles', PerfilPermisosController::class.'@roles'],
            ['PUT', 'api/crm/perfiles/usuarios/7/roles', PerfilPermisosController::class.'@guardarRoles'],
            // Las de siempre siguen donde estaban.
            ['GET', 'api/crm/perfiles', PerfilPermisosController::class.'@index'],
            ['POST', 'api/crm/perfiles/usuarios/7', PerfilPermisosController::class.'@aplicar'],
            ['GET', 'api/crm/vendedores', \App\Http\Controllers\Api\CRM\VendedorController::class.'@index'],
        ];

        foreach ($esperadas as [$metodo, $ruta, $accion]) {
            $route = app('router')->getRoutes()->match(Request::create("/{$ruta}", $metodo));
            $this->assertSame($accion, $route->getActionName(), "{$metodo} {$ruta}");
        }
    }

    public function test_las_rutas_viejas_siguen_funcionando(): void
    {
        $usuario = $this->usuario();
        $this->como($this->admin);

        $this->getJson('/api/crm/vendedores?all=1', $this->crmHeaders())->assertOk();
        $this->getJson('/api/crm/perfiles')->assertOk();
        $this->postJson("/api/crm/perfiles/usuarios/{$usuario->id}", ['enterprise_id' => $this->enterprise->id, 'perfil' => 'gerencia'])
            ->assertOk();
    }

    // ── Migración del nombre del submódulo ────────────────────────────────

    private function vendedoresDe(Enterprise $empresa, string $app = 'crm'): Submodule
    {
        return Submodule::where('slug', 'vendedores')
            ->whereHas('module', fn ($m) => $m->where('slug', 'catalogos')
                ->whereHas('application', fn ($a) => $a->where('slug', $app)->where('enterprise_id', $empresa->id)))
            ->firstOrFail();
    }

    public function test_el_sembrador_nombra_equipo_comercial_y_conserva_el_slug(): void
    {
        $sub = $this->vendedoresDe($this->enterprise);

        $this->assertSame('Equipo comercial', $sub->name);
        $this->assertSame('vendedores', $sub->slug);
    }

    public function test_migracion_renombra_solo_el_submodulo_del_crm(): void
    {
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder())->run();

        // Estado previo al despliegue: el nombre de siempre en las dos empresas.
        $aqui = $this->vendedoresDe($this->enterprise);
        $alla = $this->vendedoresDe($otra);
        Submodule::whereIn('id', [$aqui->id, $alla->id])->update(['name' => 'Vendedores']);

        // Un submódulo con el mismo slug, pero en otra aplicación: no es del CRM.
        $app = Application::create(['enterprise_id' => $this->enterprise->id, 'slug' => 'ventas-viejas', 'name' => 'Ventas', 'description' => 'Otra', 'path' => '/ventas', 'is_active' => true]);
        $modulo = Module::create(['application_id' => $app->id, 'slug' => 'catalogos', 'name' => 'Catálogos', 'is_active' => true]);
        $ajeno = Submodule::create(['module_id' => $modulo->id, 'slug' => 'vendedores', 'name' => 'Vendedores', 'is_active' => true]);

        // Otro submódulo del CRM que no se llama «Vendedores»: no se toca.
        $regiones = $this->vendedoresDe($this->enterprise)->module->submodules()->where('slug', 'regiones')->firstOrFail();

        (require database_path(self::MIGRACION))->up();

        $this->assertSame('Equipo comercial', $aqui->fresh()->name);
        $this->assertSame('Equipo comercial', $alla->fresh()->name);
        $this->assertSame('vendedores', $aqui->fresh()->slug);
        $this->assertSame('Vendedores', $ajeno->fresh()->name, 'Un submódulo vendedores fuera de la app crm no cambia.');
        $this->assertSame('Regiones', $regiones->fresh()->name);

        // Repetible.
        (require database_path(self::MIGRACION))->up();
        $this->assertSame('Equipo comercial', $aqui->fresh()->name);
    }

    public function test_migracion_no_pisa_un_nombre_personalizado_y_down_la_devuelve(): void
    {
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder())->run();
        $aqui = $this->vendedoresDe($this->enterprise);
        $alla = $this->vendedoresDe($otra);
        $aqui->update(['name' => 'Vendedores']);
        $alla->update(['name' => 'Mis vendedores']);

        $app = Application::create(['enterprise_id' => $this->enterprise->id, 'slug' => 'ventas-viejas', 'name' => 'Ventas', 'description' => 'Otra', 'path' => '/ventas', 'is_active' => true]);
        $modulo = Module::create(['application_id' => $app->id, 'slug' => 'catalogos', 'name' => 'Catálogos', 'is_active' => true]);
        $ajeno = Submodule::create(['module_id' => $modulo->id, 'slug' => 'vendedores', 'name' => 'Equipo comercial', 'is_active' => true]);

        (require database_path(self::MIGRACION))->up();
        $this->assertSame('Equipo comercial', $aqui->fresh()->name);
        $this->assertSame('Mis vendedores', $alla->fresh()->name);

        (require database_path(self::MIGRACION))->down();
        $this->assertSame('Vendedores', $aqui->fresh()->name);
        $this->assertSame('Mis vendedores', $alla->fresh()->name);
        $this->assertSame('Equipo comercial', $ajeno->fresh()->name, 'down() solo toca el CRM.');
    }
}
