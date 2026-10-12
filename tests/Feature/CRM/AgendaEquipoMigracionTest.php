<?php

namespace Tests\Feature\CRM;

use App\Models\Enterprise;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmodulePermission;
use Database\Seeders\CrmPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Migración que agrega agenda.equipo a las empresas ya sembradas y se lo
 * concede a quien ya ve al equipo (agenda.eliminar, mi-dia.equipo u
 * oportunidades.equipo). También concede oportunidades.equipo a quien puede
 * aprobar o rechazar cotizaciones, para no dejar sin aprobar a los
 * aprobadores configurados a mano.
 */
class AgendaEquipoMigracionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MIGRACION = 'migrations/2026_10_11_100000_add_crm_agenda_equipo_permission.php';

    private function subir(): void
    {
        (require database_path(self::MIGRACION))->up();
    }

    private function bajar(): void
    {
        (require database_path(self::MIGRACION))->down();
    }

    /** Tipo de permiso `$slug` del submódulo `$modulo/$submodulo` del CRM de la empresa. */
    private function tipoEn(Enterprise $empresa, string $modulo, string $submodulo, string $slug): ?SubmodulePermissionType
    {
        return SubmodulePermissionType::where('slug', $slug)
            ->whereHas('submodule', fn ($q) => $q->where('slug', $submodulo)
                ->whereHas('module', fn ($m) => $m->where('slug', $modulo)
                    ->whereHas('application', fn ($a) => $a->where('slug', 'crm')->where('enterprise_id', $empresa->id))))
            ->first();
    }

    private function concederEn(Enterprise $empresa, User $user, string $modulo, string $submodulo, string $slug, bool $otorgado = true): void
    {
        $tipo = $this->tipoEn($empresa, $modulo, $submodulo, $slug);
        $this->assertNotNull($tipo, "No existe {$modulo}.{$slug}");
        UserSubmodulePermission::create([
            'user_id' => $user->id,
            'submodule_id' => $tipo->submodule_id,
            'permission_type_id' => $tipo->id,
            'is_granted' => $otorgado,
        ]);
    }

    private function tieneEn(Enterprise $empresa, User $user, string $modulo, string $submodulo, string $slug): bool
    {
        $tipo = $this->tipoEn($empresa, $modulo, $submodulo, $slug);
        if (! $tipo) {
            return false;
        }

        return UserSubmodulePermission::where('user_id', $user->id)
            ->where('permission_type_id', $tipo->id)
            ->where('is_granted', true)
            ->exists();
    }

    private function agendaEquipo(Enterprise $empresa, User $user): bool
    {
        return $this->tieneEn($empresa, $user, 'agenda', 'agenda', 'equipo');
    }

    private function oportunidadesEquipo(Enterprise $empresa, User $user): bool
    {
        return $this->tieneEn($empresa, $user, 'oportunidades', 'oportunidades', 'equipo');
    }

    private function contarTiposAgendaEquipo(): int
    {
        return SubmodulePermissionType::where('slug', 'equipo')
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'agenda'))->count();
    }

    /** CRM sembrado en dos empresas y agenda.equipo borrado, como antes del despliegue. */
    private function prepararDosEmpresas(): Enterprise
    {
        $this->setUpCrmFixtures();
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder)->run();
        $this->tipoEn($this->enterprise, 'agenda', 'agenda', 'equipo')?->delete();
        $this->tipoEn($otra, 'agenda', 'agenda', 'equipo')?->delete();

        return $otra;
    }

    public function test_crea_el_tipo_equipo_de_agenda_en_cada_empresa(): void
    {
        $otra = $this->prepararDosEmpresas();
        $this->assertSame(0, $this->contarTiposAgendaEquipo());

        $this->subir();

        $tipo = $this->tipoEn($this->enterprise, 'agenda', 'agenda', 'equipo');
        $this->assertNotNull($tipo);
        $this->assertSame('Ver la agenda de otros vendedores', $tipo->name);
        $this->assertSame(5, (int) $tipo->order);
        $this->assertNotNull($this->tipoEn($otra, 'agenda', 'agenda', 'equipo'));
    }

    public function test_concede_agenda_equipo_a_quien_ya_ve_al_equipo(): void
    {
        $this->prepararDosEmpresas();
        $conEliminar = User::factory()->create();
        $conMiDiaEquipo = User::factory()->create();
        $conOportunidadesEquipo = User::factory()->create();
        $vendedor = User::factory()->create();
        $this->concederEn($this->enterprise, $conEliminar, 'agenda', 'agenda', 'eliminar');
        $this->concederEn($this->enterprise, $conMiDiaEquipo, 'mi-dia', 'mi-dia', 'equipo');
        $this->concederEn($this->enterprise, $conOportunidadesEquipo, 'oportunidades', 'oportunidades', 'equipo');
        foreach (['ver', 'crear', 'editar'] as $slug) {
            $this->concederEn($this->enterprise, $vendedor, 'agenda', 'agenda', $slug);
        }

        $this->subir();

        $this->assertTrue($this->agendaEquipo($this->enterprise, $conEliminar));
        $this->assertTrue($this->agendaEquipo($this->enterprise, $conMiDiaEquipo));
        $this->assertTrue($this->agendaEquipo($this->enterprise, $conOportunidadesEquipo));
        $this->assertFalse($this->agendaEquipo($this->enterprise, $vendedor));
    }

    public function test_concede_oportunidades_equipo_a_quien_aprueba_o_rechaza_cotizaciones(): void
    {
        $this->prepararDosEmpresas();
        $aprobador = User::factory()->create();
        $rechazador = User::factory()->create();
        $soloVer = User::factory()->create();
        $this->concederEn($this->enterprise, $aprobador, 'cotizaciones', 'cotizaciones', 'aprobar');
        $this->concederEn($this->enterprise, $rechazador, 'cotizaciones', 'cotizaciones', 'rechazar');
        $this->concederEn($this->enterprise, $soloVer, 'cotizaciones', 'cotizaciones', 'ver');

        $this->subir();

        $this->assertTrue($this->oportunidadesEquipo($this->enterprise, $aprobador));
        $this->assertTrue($this->oportunidadesEquipo($this->enterprise, $rechazador));
        $this->assertFalse($this->oportunidadesEquipo($this->enterprise, $soloVer));
        // El equipo de oportunidades recién concedido no arrastra el de agenda:
        // la agenda se resuelve con lo que el usuario tenía antes del despliegue.
        $this->assertFalse($this->agendaEquipo($this->enterprise, $aprobador));
    }

    public function test_los_permisos_revocados_no_cuentan(): void
    {
        $this->prepararDosEmpresas();
        $revocado = User::factory()->create();
        $this->concederEn($this->enterprise, $revocado, 'agenda', 'agenda', 'eliminar', otorgado: false);
        $this->concederEn($this->enterprise, $revocado, 'mi-dia', 'mi-dia', 'equipo', otorgado: false);
        $this->concederEn($this->enterprise, $revocado, 'oportunidades', 'oportunidades', 'equipo', otorgado: false);
        $this->concederEn($this->enterprise, $revocado, 'cotizaciones', 'cotizaciones', 'aprobar', otorgado: false);
        $this->concederEn($this->enterprise, $revocado, 'cotizaciones', 'cotizaciones', 'rechazar', otorgado: false);

        $this->subir();

        $this->assertFalse($this->agendaEquipo($this->enterprise, $revocado));
        $this->assertFalse($this->oportunidadesEquipo($this->enterprise, $revocado));
    }

    public function test_no_reactiva_un_equipo_revocado(): void
    {
        $this->setUpCrmFixtures();
        (new CrmPermisosSeeder)->run();
        $usuario = User::factory()->create();
        $this->concederEn($this->enterprise, $usuario, 'agenda', 'agenda', 'eliminar');
        $this->concederEn($this->enterprise, $usuario, 'cotizaciones', 'cotizaciones', 'aprobar');
        $this->concederEn($this->enterprise, $usuario, 'agenda', 'agenda', 'equipo', otorgado: false);
        $this->concederEn($this->enterprise, $usuario, 'oportunidades', 'oportunidades', 'equipo', otorgado: false);

        $this->subir();
        $this->subir();

        $this->assertFalse($this->agendaEquipo($this->enterprise, $usuario));
        $this->assertFalse($this->oportunidadesEquipo($this->enterprise, $usuario));
        foreach ([['agenda', 'agenda'], ['oportunidades', 'oportunidades']] as [$modulo, $submodulo]) {
            $this->assertSame(1, UserSubmodulePermission::where('user_id', $usuario->id)
                ->where('permission_type_id', $this->tipoEn($this->enterprise, $modulo, $submodulo, 'equipo')->id)
                ->count());
        }
    }

    public function test_es_idempotente_y_cada_empresa_se_resuelve_por_separado(): void
    {
        $otra = $this->prepararDosEmpresas();
        $soloEnA = User::factory()->create();
        $soloEnB = User::factory()->create();
        $this->concederEn($this->enterprise, $soloEnA, 'agenda', 'agenda', 'eliminar');
        $this->concederEn($this->enterprise, $soloEnA, 'cotizaciones', 'cotizaciones', 'aprobar');
        $this->concederEn($otra, $soloEnB, 'mi-dia', 'mi-dia', 'equipo');

        $this->subir();
        $this->subir();

        $this->assertTrue($this->agendaEquipo($this->enterprise, $soloEnA));
        $this->assertFalse($this->agendaEquipo($otra, $soloEnA));
        $this->assertFalse($this->oportunidadesEquipo($otra, $soloEnA));
        $this->assertTrue($this->agendaEquipo($otra, $soloEnB));
        $this->assertFalse($this->agendaEquipo($this->enterprise, $soloEnB));
        $this->assertSame(2, $this->contarTiposAgendaEquipo());
        $this->assertSame(1, UserSubmodulePermission::where('user_id', $soloEnA->id)
            ->where('permission_type_id', $this->tipoEn($this->enterprise, 'agenda', 'agenda', 'equipo')->id)
            ->count());
        $this->assertSame(1, UserSubmodulePermission::where('user_id', $soloEnA->id)
            ->where('permission_type_id', $this->tipoEn($this->enterprise, 'oportunidades', 'oportunidades', 'equipo')->id)
            ->count());
    }

    public function test_down_quita_solo_el_equipo_de_agenda(): void
    {
        $otra = $this->prepararDosEmpresas();
        $gerente = User::factory()->create();
        $this->concederEn($this->enterprise, $gerente, 'agenda', 'agenda', 'eliminar');
        $this->concederEn($otra, $gerente, 'mi-dia', 'mi-dia', 'equipo');
        $this->subir();
        $this->assertTrue($this->agendaEquipo($this->enterprise, $gerente));
        $this->assertTrue($this->agendaEquipo($otra, $gerente));
        $antes = UserSubmodulePermission::where('user_id', $gerente->id)->count();

        $this->bajar();

        $this->assertSame(0, $this->contarTiposAgendaEquipo());
        // mi-dia.equipo y oportunidades.equipo siguen intactos.
        $this->assertSame(2, SubmodulePermissionType::where('slug', 'equipo')
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'mi-dia'))->count());
        $this->assertSame(2, SubmodulePermissionType::where('slug', 'equipo')
            ->whereHas('submodule', fn ($q) => $q->where('slug', 'oportunidades'))->count());
        $this->assertSame($antes - 2, UserSubmodulePermission::where('user_id', $gerente->id)->count());
    }

    public function test_sin_estructura_crm_no_hace_nada(): void
    {
        $this->subir();
        $this->bajar();

        $this->assertSame(0, SubmodulePermissionType::count());
        $this->assertSame(0, UserSubmodulePermission::count());
    }
}
