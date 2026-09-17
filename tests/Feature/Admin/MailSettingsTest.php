<?php

namespace Tests\Feature\Admin;

use App\Mail\PruebaConfiguracionCorreo;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\MailSettings;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@x.com']);
    }

    private function activarSmtp(array $extra = []): void
    {
        app(SettingsService::class)->update(array_merge([
            'mail.enabled' => true,
            'mail.host' => 'smtp.x.com',
            'mail.port' => 465,
            'mail.encryption' => 'ssl',
            'mail.username' => 'usuario',
            'mail.password' => 'secreta',
            'mail.from_address' => 'no-reply@x.com',
            'mail.from_name' => 'Sentinel',
        ], $extra));
    }

    public function test_desactivado_no_toca_la_configuracion(): void
    {
        $antes = config('mail.default');

        app(MailSettings::class)->apply();

        $this->assertSame($antes, config('mail.default'));
    }

    public function test_activado_aplica_la_configuracion_smtp(): void
    {
        $this->activarSmtp();

        app(MailSettings::class)->apply();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.x.com', config('mail.mailers.smtp.host'));
        $this->assertSame(465, config('mail.mailers.smtp.port'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('usuario', config('mail.mailers.smtp.username'));
        $this->assertSame('secreta', config('mail.mailers.smtp.password'));
        $this->assertSame(10, config('mail.mailers.smtp.timeout'));
        $this->assertSame('no-reply@x.com', config('mail.from.address'));
        $this->assertSame('Sentinel', config('mail.from.name'));
    }

    public function test_cifrado_ninguno_desactiva_tls_automatico(): void
    {
        $this->activarSmtp(['mail.encryption' => 'none', 'mail.port' => 25]);

        app(MailSettings::class)->apply();

        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $this->assertFalse(config('mail.mailers.smtp.auto_tls'));
    }

    public function test_correo_de_prueba_con_smtp_desactivado_da_422(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/settings/mail/test')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Activa y guarda la configuración SMTP antes de probarla.');
    }

    public function test_correo_de_prueba_se_envia_al_admin_por_defecto(): void
    {
        $this->activarSmtp();
        Mail::fake();
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/settings/mail/test')
            ->assertOk()
            ->assertJsonPath('message', 'Correo de prueba enviado a admin@x.com.');

        Mail::assertSent(PruebaConfiguracionCorreo::class, fn ($m) => $m->hasTo('admin@x.com'));
        $log = ActivityLog::where('action', 'mail_test')->sole();
        $this->assertSame(['to' => 'admin@x.com', 'ok' => true], $log->new_values);
    }

    public function test_correo_de_prueba_a_otro_destinatario_y_validacion(): void
    {
        $this->activarSmtp();
        Mail::fake();
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/settings/mail/test', ['to' => 'no-es-correo'])
            ->assertStatus(422)->assertJsonValidationErrors('to');

        $this->postJson('/api/admin/settings/mail/test', ['to' => 'otro@x.com'])->assertOk();
        Mail::assertSent(PruebaConfiguracionCorreo::class, fn ($m) => $m->hasTo('otro@x.com'));
    }

    public function test_fallo_de_transporte_da_422_sin_la_contrasena(): void
    {
        $this->activarSmtp();
        Sanctum::actingAs($this->admin);

        $mailer = Mockery::mock();
        $mailer->shouldReceive('send')->andThrow(new \RuntimeException('Auth failed for usuario:secreta'));
        Mail::shouldReceive('to')->andReturn($mailer);
        Mail::shouldReceive('purge')->zeroOrMoreTimes();

        $respuesta = $this->postJson('/api/admin/settings/mail/test')->assertStatus(422);

        $this->assertSame('No se pudo enviar el correo: Auth failed for usuario:***', $respuesta->json('message'));
        $this->assertSame(['to' => 'admin@x.com', 'ok' => false], ActivityLog::where('action', 'mail_test')->sole()->new_values);
    }

    public function test_usuario_normal_no_puede_enviar_prueba(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));

        $this->postJson('/api/admin/settings/mail/test')->assertForbidden();
    }
}
