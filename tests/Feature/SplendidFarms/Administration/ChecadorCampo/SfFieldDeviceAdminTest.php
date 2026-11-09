<?php

namespace Tests\Feature\SplendidFarms\Administration\ChecadorCampo;

use App\Models\DevicePairing;
use App\Models\Enterprise;
use App\Models\Submodule;
use App\Models\SubmodulePermissionType;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use App\Models\UserSubmodulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

class SfFieldDeviceAdminTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    private const BASE = '/api/splendidfarms/administration/personal/field-checks/dispositivos';

    private function empresa(string $slug): Enterprise
    {
        return Enterprise::create([
            'name' => 'Empresa ' . $slug,
            'slug' => $slug,
            'description' => 'Prueba',
            'is_active' => true,
        ]);
    }

    private function usuario(Enterprise $empresa, string $role = 'user'): User
    {
        $user = User::factory()->create(['role' => $role]);
        UserEnterpriseAccess::create([
            'user_id' => $user->id,
            'enterprise_id' => $empresa->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);

        return $user;
    }

    private function concederEdit(User $user, Enterprise $empresa): void
    {
        $sub = $this->darAccesoChecadorCampo($user, $empresa);
        $tipo = SubmodulePermissionType::create([
            'submodule_id' => $sub->id,
            'slug' => 'edit',
            'name' => 'Editar',
            'description' => 'Permite editar registros en el submodulo',
            'order' => 3,
            'is_active' => true,
        ]);
        UserSubmodulePermission::create([
            'user_id' => $user->id,
            'submodule_id' => $sub->id,
            'permission_type_id' => $tipo->id,
            'is_granted' => true,
        ]);
    }

    private function emparejamiento(Enterprise $empresa, string $modo = DevicePairing::MODE_SF_FIELD, array $extra = []): DevicePairing
    {
        return DevicePairing::create(array_merge([
            'device_token_hash' => DevicePairing::hashToken(DevicePairing::generateToken()),
            'mode' => $modo,
            'enterprise_id' => $empresa->id,
            'label' => 'Celular',
            'last_used_at' => now(),
        ], $extra));
    }

    public function test_lista_solo_sf_field_de_la_empresa(): void
    {
        $sf = $this->empresa('sf-a');
        $otra = $this->empresa('sf-b');
        $admin = $this->usuario($sf, 'superadmin');
        Sanctum::actingAs($admin);

        $propio = $this->emparejamiento($sf, DevicePairing::MODE_SF_FIELD, ['label' => 'Mi celular', 'paired_by_user_id' => $admin->id]);
        $this->emparejamiento($sf, DevicePairing::MODE_SELF);
        $this->emparejamiento($sf, DevicePairing::MODE_KIOSK);
        $this->emparejamiento($otra, DevicePairing::MODE_SF_FIELD);

        $resp = $this->getJson(self::BASE . '?enterprise_id=' . $sf->id)->assertOk();

        $items = $resp->json('data.data');
        $this->assertCount(1, $items);
        $this->assertSame($propio->id, $items[0]['id']);
        $this->assertSame('Mi celular', $items[0]['label']);
        $this->assertSame($admin->id, $items[0]['paired_by_user']['id']);
        $this->assertSame($admin->name, $items[0]['paired_by_user']['name']);
        $this->assertArrayHasKey('created_at', $items[0]);
        $this->assertArrayHasKey('last_used_at', $items[0]);
        $this->assertArrayHasKey('revoked_at', $items[0]);
        $this->assertSame('activo', $items[0]['estado']);
        $this->assertStringNotContainsString('device_token_hash', $resp->getContent());
    }

    public function test_estados_calculados(): void
    {
        $sf = $this->empresa('sf-a');
        $admin = $this->usuario($sf, 'superadmin');
        Sanctum::actingAs($admin);

        $activo = $this->emparejamiento($sf, DevicePairing::MODE_SF_FIELD, ['paired_by_user_id' => $admin->id]);
        $revocado = $this->emparejamiento($sf, DevicePairing::MODE_SF_FIELD, ['paired_by_user_id' => $admin->id, 'revoked_at' => now()]);
        $caducado = $this->emparejamiento($sf, DevicePairing::MODE_SF_FIELD, ['paired_by_user_id' => $admin->id]);
        DevicePairing::whereKey($caducado->id)->update(['last_used_at' => now()->subDays(400)]);
        $sinPermiso = $this->emparejamiento($sf, DevicePairing::MODE_SF_FIELD, ['paired_by_user_id' => null]);

        $items = collect($this->getJson(self::BASE . '?enterprise_id=' . $sf->id)->assertOk()->json('data.data'))
            ->keyBy('id');

        $this->assertSame('activo', $items[$activo->id]['estado']);
        $this->assertSame('revocado', $items[$revocado->id]['estado']);
        $this->assertSame('caducado', $items[$caducado->id]['estado']);
        $this->assertSame('sin_permiso', $items[$sinPermiso->id]['estado']);
    }

    public function test_lista_requiere_edit_del_checador(): void
    {
        $sf = $this->empresa('sf-a');
        $user = $this->usuario($sf);
        $this->darAccesoChecadorCampo($user, $sf);
        Sanctum::actingAs($user);

        $this->getJson(self::BASE . '?enterprise_id=' . $sf->id)->assertForbidden();

        Sanctum::actingAs($this->usuario($sf, 'superadmin'));
        $this->getJson(self::BASE . '?enterprise_id=' . $sf->id)->assertOk();
    }

    public function test_usuario_con_edit_ve_la_lista(): void
    {
        $sf = $this->empresa('sf-a');
        $user = $this->usuario($sf);
        $this->concederEdit($user, $sf);
        Sanctum::actingAs($user);

        $this->getJson(self::BASE . '?enterprise_id=' . $sf->id)->assertOk();
    }

    public function test_lista_sin_acceso_a_la_empresa_da_403(): void
    {
        $sf = $this->empresa('sf-a');
        $otra = $this->empresa('sf-b');
        $user = $this->usuario($otra);
        $this->concederEdit($user, $sf); // tiene edit pero sin acceso a sf
        Sanctum::actingAs($user);

        $this->getJson(self::BASE . '?enterprise_id=' . $sf->id)->assertForbidden();
    }

    public function test_revocar_marca_revoked_at(): void
    {
        $sf = $this->empresa('sf-a');
        $user = $this->usuario($sf);
        $this->concederEdit($user, $sf);
        Sanctum::actingAs($user);
        $pairing = $this->emparejamiento($sf);

        $resp = $this->postJson(self::BASE . '/' . $pairing->id . '/revocar')->assertOk();

        $this->assertNotNull($pairing->fresh()->revoked_at);
        $this->assertSame('revocado', $resp->json('data.estado'));
        $this->assertStringNotContainsString('device_token_hash', $resp->getContent());
    }

    public function test_revocar_sin_edit_da_403(): void
    {
        $sf = $this->empresa('sf-a');
        $user = $this->usuario($sf);
        $this->darAccesoChecadorCampo($user, $sf);
        Sanctum::actingAs($user);
        $pairing = $this->emparejamiento($sf);

        $this->postJson(self::BASE . '/' . $pairing->id . '/revocar')->assertForbidden();
        $this->assertNull($pairing->fresh()->revoked_at);
    }

    public function test_revocar_un_kiosk_de_ge_da_404(): void
    {
        $ge = $this->empresa('ge');
        Sanctum::actingAs($this->usuario($ge, 'superadmin'));
        $kiosk = $this->emparejamiento($ge, DevicePairing::MODE_KIOSK);

        $this->postJson(self::BASE . '/' . $kiosk->id . '/revocar')->assertNotFound();
        $this->assertNull($kiosk->fresh()->revoked_at);
    }

    public function test_revocar_emparejamiento_de_otra_empresa_sin_acceso_da_403(): void
    {
        $sf = $this->empresa('sf-a');
        $otra = $this->empresa('sf-b');
        $user = $this->usuario($sf);
        $this->concederEdit($user, $sf);
        Sanctum::actingAs($user);
        $ajeno = $this->emparejamiento($otra);

        $this->postJson(self::BASE . '/' . $ajeno->id . '/revocar')->assertForbidden();
        $this->assertNull($ajeno->fresh()->revoked_at);
    }

    public function test_migracion_de_tipos_es_idempotente(): void
    {
        $a = $this->empresa('sf-a');
        $b = $this->empresa('sf-b');
        $user = User::factory()->create();
        $subA = $this->darAccesoChecadorCampo($user, $a);
        $subB = $this->darAccesoChecadorCampo($user, $b);
        $otroSub = Submodule::create(['module_id' => $subA->module_id, 'slug' => 'empleados', 'name' => 'Empleados', 'is_active' => true]);

        // Ya existe 'edit' en A con otro texto: no se debe tocar ni duplicar.
        SubmodulePermissionType::create([
            'submodule_id' => $subA->id, 'slug' => 'edit', 'name' => 'Editar X',
            'description' => 'x', 'order' => 1, 'is_active' => true,
        ]);

        $correr = fn () => (require database_path('migrations/2026_11_08_100100_ensure_checador_campo_permission_types.php'))->up();
        $correr();
        $correr();

        foreach ([$subA, $subB] as $sub) {
            $slugs = SubmodulePermissionType::where('submodule_id', $sub->id)->orderBy('slug')->pluck('slug')->all();
            $this->assertSame(['create', 'delete', 'edit', 'view'], $slugs);
        }
        $this->assertSame('Editar X', SubmodulePermissionType::where('submodule_id', $subA->id)->where('slug', 'edit')->value('name'));
        $this->assertSame('Ver', SubmodulePermissionType::where('submodule_id', $subB->id)->where('slug', 'view')->value('name'));
        $this->assertSame(0, SubmodulePermissionType::where('submodule_id', $otroSub->id)->count());
    }
}
