<?php

namespace Tests\Feature\Admin;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): SettingsService
    {
        return app(SettingsService::class);
    }

    public function test_devuelve_valores_por_defecto_sin_filas(): void
    {
        $s = $this->servicio();

        $this->assertSame(120, $s->get('session.idle_minutes'));
        $this->assertSame(8, $s->get('password.min_length'));
        $this->assertFalse($s->get('password.require_symbols'));
        $this->assertFalse($s->get('mail.enabled'));
        $this->assertSame(587, $s->get('mail.port'));
        $this->assertSame('tls', $s->get('mail.encryption'));
        $this->assertSame(config('app.name'), $s->get('mail.from_name'));
    }

    public function test_guarda_lee_y_cifra_la_contrasena_smtp(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $s = $this->servicio();

        $s->update(['session.idle_minutes' => 30, 'mail.password' => 'secreta'], $admin);

        $this->assertSame(30, $s->get('session.idle_minutes'));
        $this->assertSame('secreta', $s->get('mail.password'));

        $fila = SystemSetting::where('key', 'mail.password')->first();
        $this->assertStringNotContainsString('secreta', $fila->value);
        $this->assertSame('secreta', Crypt::decryptString(json_decode($fila->value)));
        $this->assertSame($admin->id, $fila->updated_by);
    }

    public function test_contrasena_vacia_conserva_la_guardada(): void
    {
        $s = $this->servicio();
        $s->update(['mail.password' => 'secreta']);
        $s->update(['mail.password' => '', 'mail.host' => 'smtp.x.com']);
        $s->update(['mail.host' => 'smtp.y.com']);

        $this->assertSame('secreta', $s->get('mail.password'));
        $this->assertSame('smtp.y.com', $s->get('mail.host'));
    }

    public function test_ignora_claves_desconocidas_y_devuelve_solo_cambios(): void
    {
        $s = $this->servicio();
        $cambios = $s->update([
            'session.idle_minutes' => 120, // igual al por defecto: no es cambio
            'password.min_length' => 10,
            'mail.password' => 'x',
            'otra.cosa' => 1,
        ]);

        $this->assertSame(['password.min_length' => 8, 'mail.password' => '***'], $cambios['antes']);
        $this->assertSame(['password.min_length' => 10, 'mail.password' => '***'], $cambios['despues']);
        $this->assertNull(SystemSetting::where('key', 'otra.cosa')->first());
    }

    public function test_cache_se_invalida_al_guardar(): void
    {
        $s = $this->servicio();
        $this->assertSame(120, $s->get('session.idle_minutes'));
        $this->assertTrue(Cache::has('system_settings'));

        $s->update(['session.idle_minutes' => 15]);

        $this->assertSame(15, $s->get('session.idle_minutes'));
    }

    public function test_valor_corrupto_usa_el_por_defecto_y_avisa(): void
    {
        Log::spy();
        SystemSetting::create(['key' => 'session.idle_minutes', 'value' => json_encode(99999)]);
        SystemSetting::create(['key' => 'mail.password', 'value' => json_encode('no-es-cifrado')]);

        $valores = $this->servicio()->all();
        $this->assertSame(120, $valores['session.idle_minutes']);
        $this->assertSame('', $valores['mail.password']);
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_for_client_oculta_la_contrasena(): void
    {
        $s = $this->servicio();
        $this->assertFalse($s->forClient()['mail']['password_set']);

        $s->update(['mail.password' => 'secreta']);
        $datos = $s->forClient();

        $this->assertArrayNotHasKey('password', $datos['mail']);
        $this->assertTrue($datos['mail']['password_set']);
        $this->assertSame(120, $datos['session']['idle_minutes']);
        $this->assertSame(8, $datos['password']['min_length']);
    }
}
