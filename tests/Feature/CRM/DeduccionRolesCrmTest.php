<?php

namespace Tests\Feature\CRM;

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
use App\Services\CRM\CrmPerfilPermisosService;
use App\Services\CRM\DeduccionRolesCrm;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Los roles del Equipo comercial (vende, gerencia, catálogos) no se guardan:
 * se deducen de los permisos reales y de la ficha de vendedor.
 */
class DeduccionRolesCrmTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    private DeduccionRolesCrm $deduccion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        (new CrmPermisosSeeder())->run();
        $this->deduccion = new DeduccionRolesCrm();
    }

    // ── Auxiliares ────────────────────────────────────────────────────────

    /** Definiciones completas (sin intersectar) en la forma que recibe deducir(). */
    private function defs(): array
    {
        return [
            'base' => DeduccionRolesCrm::claves(CrmPerfilPermisosService::definicionRoles(['vende'])),
            'gerencia' => DeduccionRolesCrm::claves(CrmPerfilPermisosService::definicionRoles(['gerencia'])),
            'catalogos' => DeduccionRolesCrm::claves(CrmPerfilPermisosService::definicionRoles(['catalogos'])),
        ];
    }

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

    private function conPerfil(string $perfil, ?Enterprise $empresa = null, bool $acceso = true): User
    {
        $empresa ??= $this->enterprise;
        $usuario = $this->usuario($empresa, $acceso);
        (new CrmPerfilPermisosService())->aplicar($usuario, $empresa, $perfil);

        return $usuario;
    }

    private function ficha(?User $usuario, bool $activo = true, ?Enterprise $empresa = null, string $nombre = 'Ficha'): CrmVendedor
    {
        return CrmVendedor::create([
            'empresa_id' => ($empresa ?? $this->enterprise)->id,
            'user_id' => $usuario?->id,
            'nombre' => $nombre,
            'email' => $usuario?->email ?? 'sin-usuario-'.uniqid().'@example.com',
            'activo' => $activo,
        ]);
    }

    private function abrirOportunidades(CrmVendedor $ficha, int $cuantas, string $etapa = 'prospecto'): void
    {
        for ($i = 0; $i < $cuantas; $i++) {
            CrmOportunidad::create([
                'empresa_id' => $ficha->empresa_id,
                'vendedor_id' => $ficha->id,
                'nombre' => "Oportunidad {$i}",
                'etapa' => $etapa,
            ]);
        }
    }

    private function fila(\Illuminate\Support\Collection $filas, ?int $userId = null, ?int $vendedorId = null): ?array
    {
        return $filas->first(fn ($f) => ($userId === null || $f['user_id'] === $userId)
            && ($vendedorId === null || $f['vendedor_id'] === $vendedorId));
    }

    private function appCrmId(): int
    {
        return Application::where('enterprise_id', $this->enterprise->id)->where('slug', 'crm')->value('id');
    }

    private function modulo(string $slug): Module
    {
        return Module::where('application_id', $this->appCrmId())->where('slug', $slug)->firstOrFail();
    }

    private function submodulo(string $modulo, string $slug): Submodule
    {
        return Submodule::where('module_id', $this->modulo($modulo)->id)->where('slug', $slug)->firstOrFail();
    }

    // ── Deducción pura ────────────────────────────────────────────────────

    public function test_vendedor_con_ficha_sale_limpio(): void
    {
        $d = $this->defs();

        $r = DeduccionRolesCrm::deducir($d['base'], $d, true, true);

        $this->assertSame(
            ['vende' => true, 'gerencia' => false, 'catalogos' => false, 'ajustado' => false, 'aviso' => null],
            $r,
        );
    }

    public function test_gerente_con_catalogos_a_mano_suma_los_dos(): void
    {
        $d = $this->defs();

        $r = DeduccionRolesCrm::deducir(array_merge($d['gerencia'], $d['catalogos']), $d, false, false);

        $this->assertTrue($r['gerencia']);
        $this->assertTrue($r['catalogos']);
        $this->assertFalse($r['vende']);
        $this->assertFalse($r['ajustado']);
        $this->assertNull($r['aviso']);
    }

    public function test_perfil_vendedor_sin_ficha_avisa_y_no_es_ajustado(): void
    {
        $d = $this->defs();

        $r = DeduccionRolesCrm::deducir($d['base'], $d, false, false);

        $this->assertFalse($r['vende']);
        $this->assertFalse($r['ajustado']);
        $this->assertSame('falta_ficha', $r['aviso']);
    }

    public function test_ficha_inactiva_avisa(): void
    {
        $d = $this->defs();

        $r = DeduccionRolesCrm::deducir($d['base'], $d, true, false);

        $this->assertFalse($r['vende']);
        $this->assertFalse($r['ajustado']);
        $this->assertSame('ficha_inactiva', $r['aviso']);
    }

    public function test_un_permiso_de_mas_marca_ajustado(): void
    {
        $d = $this->defs();

        $r = DeduccionRolesCrm::deducir(array_merge($d['base'], ['catalogos.regiones.ver']), $d, true, true);

        $this->assertTrue($r['ajustado']);
        $this->assertTrue($r['vende']);
    }

    public function test_le_falta_un_permiso_de_gerencia_no_es_gerencia(): void
    {
        $d = $this->defs();
        $reales = array_values(array_diff($d['gerencia'], ['dashboard.dashboard.ejecutivo']));

        $r = DeduccionRolesCrm::deducir($reales, $d, false, false);

        $this->assertFalse($r['gerencia']);
        $this->assertTrue($r['ajustado']);
    }

    public function test_rol_con_definicion_vacia_no_se_deduce(): void
    {
        $d = $this->defs();
        $d['catalogos'] = [];

        $r = DeduccionRolesCrm::deducir([], $d, false, false);

        $this->assertFalse($r['catalogos']);
        $this->assertFalse($r['ajustado']);
    }

    // ── Deducción contra la base de datos ────────────────────────────────

    public function test_dePersona_usa_la_forma_anidada(): void
    {
        $usuario = $this->conPerfil('vendedor');
        $ficha = $this->ficha($usuario);
        $this->abrirOportunidades($ficha, 2);
        $this->abrirOportunidades($ficha, 1, 'cerrado_ganado');

        $r = $this->deduccion->dePersona($usuario, $this->enterprise);

        $this->assertSame(['vende' => true, 'gerencia' => false, 'catalogos' => false], $r['roles']);
        $this->assertFalse($r['ajustado']);
        $this->assertNull($r['aviso']);
        $this->assertSame(2, $r['oportunidades_abiertas']);
    }

    public function test_perfil_gerencia_aplicado_se_deduce_como_gerencia(): void
    {
        $usuario = $this->conPerfil('gerencia');

        $r = $this->deduccion->dePersona($usuario, $this->enterprise);

        $this->assertSame(['vende' => false, 'gerencia' => true, 'catalogos' => false], $r['roles']);
        $this->assertFalse($r['ajustado']);
        $this->assertSame(0, $r['oportunidades_abiertas']);
    }

    public function test_perfil_administracion_aplicado_se_deduce_como_catalogos(): void
    {
        $usuario = $this->conPerfil('administracion');

        $r = $this->deduccion->dePersona($usuario, $this->enterprise);

        $this->assertSame(['vende' => false, 'gerencia' => false, 'catalogos' => true], $r['roles']);
        $this->assertFalse($r['ajustado']);
    }

    public function test_definicion_se_intersecta_con_lo_que_existe_en_el_crm(): void
    {
        // A un CRM sin dialpad.editar no se le exige: borramos ese tipo y el
        // perfil gerencia (que ya no puede otorgarlo) sigue saliendo limpio.
        $sub = $this->submodulo('integraciones', 'dialpad');
        $tipo = SubmodulePermissionType::where('submodule_id', $sub->id)->where('slug', 'editar')->firstOrFail();
        $usuario = $this->conPerfil('gerencia');
        UserSubmodulePermission::where('permission_type_id', $tipo->id)->delete();
        $tipo->delete();

        $r = $this->deduccion->dePersona($usuario, $this->enterprise);

        $this->assertTrue($r['roles']['gerencia']);
        $this->assertFalse($r['ajustado']);
    }

    public function test_empresa_sin_crm_lanza_excepcion(): void
    {
        $otra = $this->crearOtraEmpresa();
        // La semilla corrió antes de crear esta empresa: no tiene CRM.

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La empresa no tiene el CRM Comercial configurado.');

        $this->deduccion->dePersona($this->usuario(), $otra);
    }

    public function test_appCrm_devuelve_la_aplicacion_de_la_empresa(): void
    {
        $this->assertSame($this->appCrmId(), $this->deduccion->appCrm($this->enterprise)->id);
    }

    public function test_permiso_concedido_con_acceso_apagado_no_cuenta(): void
    {
        $usuario = $this->conPerfil('gerencia');
        $this->assertTrue($this->deduccion->dePersona($usuario, $this->enterprise)['roles']['gerencia']);

        // Módulo apagado.
        UserModuleAccess::where('user_id', $usuario->id)->where('module_id', $this->modulo('agenda')->id)
            ->update(['is_active' => false]);
        $this->assertFalse($this->deduccion->dePersona($usuario, $this->enterprise)['roles']['gerencia']);
        UserModuleAccess::where('user_id', $usuario->id)->update(['is_active' => true]);
        $this->assertTrue($this->deduccion->dePersona($usuario, $this->enterprise)['roles']['gerencia']);

        // Submódulo apagado.
        UserSubmoduleAccess::where('user_id', $usuario->id)->where('submodule_id', $this->submodulo('agenda', 'agenda')->id)
            ->update(['is_active' => false]);
        $this->assertFalse($this->deduccion->dePersona($usuario, $this->enterprise)['roles']['gerencia']);
        UserSubmoduleAccess::where('user_id', $usuario->id)->update(['is_active' => true]);
        $this->assertTrue($this->deduccion->dePersona($usuario, $this->enterprise)['roles']['gerencia']);

        // Aplicación apagada.
        UserApplicationAccess::where('user_id', $usuario->id)->where('application_id', $this->appCrmId())
            ->update(['is_active' => false]);
        $r = $this->deduccion->dePersona($usuario, $this->enterprise);
        $this->assertSame(['vende' => false, 'gerencia' => false, 'catalogos' => false], $r['roles']);
    }

    public function test_tipo_de_otro_submodulo_no_cuenta(): void
    {
        $usuario = $this->conPerfil('vendedor');
        $this->ficha($usuario);

        // Con el acceso encendido, solo el tipo cruzado puede dejarla fuera.
        UserModuleAccess::where('user_id', $usuario->id)->where('module_id', $this->modulo('catalogos')->id)
            ->update(['is_active' => true]);
        UserSubmoduleAccess::where('user_id', $usuario->id)->where('submodule_id', $this->submodulo('catalogos', 'regiones')->id)
            ->update(['is_active' => true]);

        // Fila cruzada: el submódulo es regiones pero el tipo es de dashboard.ejecutivo.
        $tipoAjeno = SubmodulePermissionType::where('submodule_id', $this->submodulo('dashboard', 'dashboard')->id)
            ->where('slug', 'ejecutivo')->firstOrFail();
        UserSubmodulePermission::create([
            'user_id' => $usuario->id,
            'submodule_id' => $this->submodulo('catalogos', 'regiones')->id,
            'permission_type_id' => $tipoAjeno->id,
            'is_granted' => true,
        ]);

        $r = $this->deduccion->dePersona($usuario, $this->enterprise);

        $this->assertFalse($r['ajustado']);
        $this->assertTrue($r['roles']['vende']);
        $this->assertFalse($r['roles']['gerencia']);
    }

    public function test_de_empresa_lista_personas_fichas_sin_usuario_e_inactivas_al_final(): void
    {
        $conPerfil = $this->conPerfil('gerencia');
        $vendedor = $this->conPerfil('vendedor');
        $fichaVendedor = $this->ficha($vendedor, true, null, 'Beto Vendedor');
        $sinUsuario = $this->ficha(null, true, null, 'Ana Sin Usuario');
        $usuarioInactivo = $this->usuario();
        $inactiva = $this->ficha($usuarioInactivo, false, null, 'Aaron Inactivo');
        $this->abrirOportunidades($inactiva, 3);

        $filas = $this->deduccion->deEmpresa($this->enterprise);

        $this->assertNotNull($this->fila($filas, $conPerfil->id));
        $this->assertNotNull($this->fila($filas, null, $sinUsuario->id));
        $this->assertNotNull($this->fila($filas, $usuarioInactivo->id));

        $fSin = $this->fila($filas, null, $sinUsuario->id);
        $this->assertNull($fSin['user_id']);
        $this->assertSame('Ana Sin Usuario', $fSin['nombre']);
        $this->assertSame(['vende' => true, 'gerencia' => false, 'catalogos' => false], $fSin['roles']);
        $this->assertTrue($fSin['ficha_activa']);

        $fVend = $this->fila($filas, $vendedor->id);
        $this->assertSame($fichaVendedor->id, $fVend['vendedor_id']);
        $this->assertSame(['vende' => true, 'gerencia' => false, 'catalogos' => false], $fVend['roles']);

        $fGer = $this->fila($filas, $conPerfil->id);
        $this->assertNull($fGer['vendedor_id']);
        $this->assertSame($conPerfil->name, $fGer['nombre']);
        $this->assertSame($conPerfil->email, $fGer['email']);
        $this->assertTrue($fGer['roles']['gerencia']);
        $this->assertFalse($fGer['ficha_activa']);

        $fInactiva = $this->fila($filas, $usuarioInactivo->id);
        $this->assertFalse($fInactiva['ficha_activa']);
        $this->assertSame(3, $fInactiva['oportunidades_abiertas']);
        $this->assertSame('ficha_inactiva', $fInactiva['aviso']);

        // Las inactivas van al final.
        $this->assertSame($usuarioInactivo->id, $filas->last()['user_id']);
    }

    public function test_de_empresa_ordena_las_activas_por_nombre(): void
    {
        $this->ficha(null, true, null, 'Zeta');
        $this->ficha(null, true, null, 'Alfa');
        $this->ficha(null, false, null, 'Aaa Inactiva');

        $nombres = $this->deduccion->deEmpresa($this->enterprise)->pluck('nombre')->all();

        $this->assertLessThan(array_search('Zeta', $nombres), array_search('Alfa', $nombres));
        $this->assertSame('Aaa Inactiva', end($nombres));
    }

    public function test_ficha_de_usuario_sin_acceso_avisa(): void
    {
        $sinAcceso = $this->usuario(null, false);
        $this->ficha($sinAcceso, true, null, 'Sin Acceso');

        $filas = $this->deduccion->deEmpresa($this->enterprise);
        $fila = $this->fila($filas, $sinAcceso->id);

        $this->assertNotNull($fila);
        $this->assertSame('sin_acceso', $fila['aviso']);
        $this->assertSame('sin_acceso', $this->deduccion->dePersona($sinAcceso, $this->enterprise)['aviso']);
    }

    public function test_usuario_con_permisos_pero_sin_acceso_a_la_empresa_no_sale(): void
    {
        $sinAcceso = $this->conPerfil('gerencia', null, false);

        $this->assertNull($this->fila($this->deduccion->deEmpresa($this->enterprise), $sinAcceso->id));
    }

    public function test_no_incluye_gente_de_otra_empresa(): void
    {
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder())->run();
        $ajeno = $this->conPerfil('gerencia', $otra);
        $fichaAjena = $this->ficha(null, true, $otra, 'Ajena');

        $filas = $this->deduccion->deEmpresa($this->enterprise);

        $this->assertNull($this->fila($filas, $ajeno->id));
        $this->assertNull($this->fila($filas, null, $fichaAjena->id));
        $this->assertNotNull($this->fila($this->deduccion->deEmpresa($otra), $ajeno->id));
    }

    public function test_usuarios_disponibles_son_los_de_la_empresa_sin_permisos_ni_ficha(): void
    {
        $libre = $this->usuario();
        $conPermisos = $this->conPerfil('vendedor');
        $conFicha = $this->usuario();
        $this->ficha($conFicha);
        $sinAcceso = $this->usuario(null, false);
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder())->run();
        $deOtra = $this->usuario($otra);

        $ids = $this->deduccion->usuariosDisponibles($this->enterprise)->pluck('id')->all();

        $this->assertContains($libre->id, $ids);
        $this->assertNotContains($conPermisos->id, $ids);
        $this->assertNotContains($conFicha->id, $ids);
        $this->assertNotContains($sinAcceso->id, $ids);
        $this->assertNotContains($deOtra->id, $ids);

        $uno = $this->deduccion->usuariosDisponibles($this->enterprise)->firstWhere('id', $libre->id);
        $this->assertSame(['id', 'name', 'email'], array_keys($uno));
        $this->assertSame($libre->name, $uno['name']);
    }

    public function test_de_empresa_no_consulta_por_persona(): void
    {
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->deduccion->deEmpresa($this->enterprise);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $agregar = function (int $cuantas): void {
            for ($i = 0; $i < $cuantas; $i++) {
                $u = $this->conPerfil($i % 2 ? 'gerencia' : 'vendedor');
                $f = $this->ficha($u);
                $this->abrirOportunidades($f, 1);
            }
        };

        $agregar(3);
        $conTres = $contar();
        $agregar(27);
        $conTreinta = $contar();

        $this->assertSame($conTres, $conTreinta);
    }
}
