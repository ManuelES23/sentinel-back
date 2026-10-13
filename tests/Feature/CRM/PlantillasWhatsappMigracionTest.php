<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmPlantillaWhatsapp;
use App\Models\Enterprise;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserSubmoduleAccess;
use App\Models\UserSubmodulePermission;
use App\Support\CRM\PlantillasWhatsappPredeterminadas;
use Database\Seeders\CrmPermisosSeeder;
use Database\Seeders\CrmPlantillasWhatsappSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Migración que agrega catalogos.plantillas-whatsapp a los CRM ya sembrados,
 * se lo concede (con el acceso al menú) a quien ya edita la configuración
 * comercial y siembra las 4 plantillas por empresa (spec 2026-10-12 §4.2).
 */
class PlantillasWhatsappMigracionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MIGRACION = 'migrations/2026_10_12_200100_add_crm_plantillas_whatsapp_submodule.php';

    private const NOMBRES = ['Seguimiento', 'Envío de cotización', 'Primer contacto', 'Recordatorio de cita'];

    private function subir(): void
    {
        (require database_path(self::MIGRACION))->up();
    }

    private function bajar(): void
    {
        (require database_path(self::MIGRACION))->down();
    }

    private function submoduloEn(Enterprise $empresa, string $slug): ?Submodule
    {
        return Submodule::where('slug', $slug)
            ->whereHas('module', fn ($m) => $m->where('slug', 'catalogos')
                ->whereHas('application', fn ($a) => $a->where('slug', 'crm')->where('enterprise_id', $empresa->id)))
            ->first();
    }

    private function tipoEn(Enterprise $empresa, string $submodulo, string $slug): ?SubmodulePermissionType
    {
        $sub = $this->submoduloEn($empresa, $submodulo);

        return $sub ? SubmodulePermissionType::where('submodule_id', $sub->id)->where('slug', $slug)->first() : null;
    }

    private function concederEn(Enterprise $empresa, User $user, string $submodulo, string $slug, bool $otorgado = true): void
    {
        $tipo = $this->tipoEn($empresa, $submodulo, $slug);
        $this->assertNotNull($tipo, "No existe catalogos.{$submodulo}.{$slug}");
        UserSubmodulePermission::create([
            'user_id' => $user->id,
            'submodule_id' => $tipo->submodule_id,
            'permission_type_id' => $tipo->id,
            'is_granted' => $otorgado,
        ]);
    }

    /** Slugs concedidos (ordenados) de plantillas-whatsapp para el usuario en esa empresa. */
    private function permisosDePlantillas(Enterprise $empresa, User $user): array
    {
        $sub = $this->submoduloEn($empresa, 'plantillas-whatsapp');
        if (! $sub) {
            return [];
        }

        return UserSubmodulePermission::where('user_id', $user->id)
            ->where('submodule_id', $sub->id)
            ->where('is_granted', true)
            ->with('permissionType:id,slug')
            ->get()
            ->pluck('permissionType.slug')
            ->sort()
            ->values()
            ->all();
    }

    private function tieneAcceso(Enterprise $empresa, User $user): bool
    {
        $sub = $this->submoduloEn($empresa, 'plantillas-whatsapp');

        return $sub !== null && UserSubmoduleAccess::where('user_id', $user->id)
            ->where('submodule_id', $sub->id)
            ->where('is_active', true)
            ->exists();
    }

    private function nombresDePlantillas(Enterprise $empresa): array
    {
        return CrmPlantillaWhatsapp::where('empresa_id', $empresa->id)->orderBy('orden')->pluck('nombre')->all();
    }

    /** CRM sembrado en dos empresas y sin el submódulo nuevo, como antes del despliegue. */
    private function prepararDosEmpresas(): Enterprise
    {
        $this->setUpCrmFixtures();
        $otra = $this->crearOtraEmpresa();
        (new CrmPermisosSeeder)->run();

        foreach ([$this->enterprise, $otra] as $empresa) {
            $sub = $this->submoduloEn($empresa, 'plantillas-whatsapp');
            SubmodulePermissionType::where('submodule_id', $sub->id)->delete();
            $sub->delete();
        }

        return $otra;
    }

    public function test_crea_el_submodulo_y_sus_cuatro_permisos_en_cada_empresa(): void
    {
        $otra = $this->prepararDosEmpresas();
        $this->assertNull($this->submoduloEn($this->enterprise, 'plantillas-whatsapp'));

        $this->subir();

        foreach ([$this->enterprise, $otra] as $empresa) {
            $sub = $this->submoduloEn($empresa, 'plantillas-whatsapp');
            $this->assertNotNull($sub);
            $this->assertSame('Plantillas de WhatsApp', $sub->name);
            $this->assertSame('MessageCircle', $sub->icon);
            $this->assertSame(7, (int) $sub->order);
            $this->assertSame(
                ['crear', 'editar', 'eliminar', 'ver'],
                SubmodulePermissionType::where('submodule_id', $sub->id)->pluck('slug')->sort()->values()->all(),
            );
        }
    }

    public function test_concede_permisos_y_acceso_solo_a_quien_edita_la_configuracion_comercial(): void
    {
        $otra = $this->prepararDosEmpresas();
        $admin = User::factory()->create();
        $soloVer = User::factory()->create();
        $revocado = User::factory()->create();
        $this->concederEn($this->enterprise, $admin, 'configuracion-comercial', 'editar');
        $this->concederEn($this->enterprise, $soloVer, 'configuracion-comercial', 'ver');
        $this->concederEn($this->enterprise, $revocado, 'configuracion-comercial', 'editar', otorgado: false);

        $this->subir();

        $this->assertSame(['crear', 'editar', 'eliminar', 'ver'], $this->permisosDePlantillas($this->enterprise, $admin));
        $this->assertTrue($this->tieneAcceso($this->enterprise, $admin));
        $this->assertSame([], $this->permisosDePlantillas($this->enterprise, $soloVer));
        $this->assertFalse($this->tieneAcceso($this->enterprise, $soloVer));
        $this->assertSame([], $this->permisosDePlantillas($this->enterprise, $revocado));
        // Cada empresa se resuelve por separado.
        $this->assertSame([], $this->permisosDePlantillas($otra, $admin));
        $this->assertFalse($this->tieneAcceso($otra, $admin));
    }

    public function test_no_revive_un_permiso_ni_un_acceso_revocados(): void
    {
        $this->prepararDosEmpresas();
        $this->subir(); // Crea el submódulo; todavía nadie edita la configuración comercial.
        $admin = User::factory()->create();
        $this->concederEn($this->enterprise, $admin, 'configuracion-comercial', 'editar');
        $this->concederEn($this->enterprise, $admin, 'plantillas-whatsapp', 'eliminar', otorgado: false);
        UserSubmoduleAccess::create([
            'user_id' => $admin->id,
            'submodule_id' => $this->submoduloEn($this->enterprise, 'plantillas-whatsapp')->id,
            'is_active' => false,
        ]);

        $this->subir();

        $this->assertSame(['crear', 'editar', 'ver'], $this->permisosDePlantillas($this->enterprise, $admin));
        $this->assertFalse($this->tieneAcceso($this->enterprise, $admin));
        $this->assertSame(1, UserSubmodulePermission::where('user_id', $admin->id)
            ->where('permission_type_id', $this->tipoEn($this->enterprise, 'plantillas-whatsapp', 'eliminar')->id)
            ->count());
    }

    public function test_es_idempotente_con_dos_corridas_y_con_down_y_up(): void
    {
        $otra = $this->prepararDosEmpresas();
        $admin = User::factory()->create();
        $this->concederEn($this->enterprise, $admin, 'configuracion-comercial', 'editar');
        $submodulos = fn () => Submodule::where('slug', 'plantillas-whatsapp')->pluck('id');

        $this->subir();
        $this->subir();

        $this->assertCount(2, $submodulos());
        $this->assertSame(8, SubmodulePermissionType::whereIn('submodule_id', $submodulos())->count());
        $this->assertSame(4, UserSubmodulePermission::where('user_id', $admin->id)->whereIn('submodule_id', $submodulos())->count());
        $this->assertSame(1, UserSubmoduleAccess::where('user_id', $admin->id)->whereIn('submodule_id', $submodulos())->count());
        $this->assertSame(self::NOMBRES, $this->nombresDePlantillas($this->enterprise));
        $this->assertSame(self::NOMBRES, $this->nombresDePlantillas($otra));

        $this->bajar();

        $this->assertCount(0, $submodulos());
        $this->assertSame(0, UserSubmoduleAccess::where('user_id', $admin->id)->count());
        // down() no borra plantillas: la tabla la quita su propia migración.
        $this->assertSame(8, CrmPlantillaWhatsapp::count());

        $this->subir();

        $this->assertSame(['crear', 'editar', 'eliminar', 'ver'], $this->permisosDePlantillas($this->enterprise, $admin));
        $this->assertSame(8, CrmPlantillaWhatsapp::count());
    }

    public function test_siembra_las_plantillas_solo_en_empresas_sin_ninguna(): void
    {
        $otra = $this->prepararDosEmpresas();
        CrmPlantillaWhatsapp::create(['empresa_id' => $otra->id, 'nombre' => 'Propia', 'cuerpo' => 'Hola {{nombre}}']);

        $this->subir();

        $this->assertSame(self::NOMBRES, $this->nombresDePlantillas($this->enterprise));
        $this->assertSame(['Propia'], $this->nombresDePlantillas($otra));

        $envio = CrmPlantillaWhatsapp::where('empresa_id', $this->enterprise->id)->where('nombre', 'Envío de cotización')->first();
        $this->assertSame(PlantillasWhatsappPredeterminadas::PLANTILLAS[1]['cuerpo'], $envio->cuerpo);
        $this->assertTrue($envio->activo);
        $this->assertSame(2, $envio->orden);
    }

    public function test_sin_estructura_crm_no_hace_nada(): void
    {
        $this->subir();
        $this->bajar();

        $this->assertSame(0, Submodule::count());
        $this->assertSame(0, CrmPlantillaWhatsapp::count());
    }

    public function test_el_seeder_de_plantillas_siembra_las_empresas_con_crm_sin_duplicar(): void
    {
        $this->setUpCrmFixtures();
        (new CrmPermisosSeeder)->run();
        $sinCrm = $this->crearOtraEmpresa(); // Creada después del seeder: no tiene CRM.

        (new CrmPlantillasWhatsappSeeder)->run();
        (new CrmPlantillasWhatsappSeeder)->run();

        $this->assertSame(self::NOMBRES, $this->nombresDePlantillas($this->enterprise));
        $this->assertSame([], $this->nombresDePlantillas($sinCrm));
    }
}
