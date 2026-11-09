<?php

namespace Tests\Feature\SplendidFarms\Administration\ChecadorCampo;

use App\Models\ActivityLog;
use App\Models\DevicePairing;
use App\Models\Enterprise;
use App\Models\SfEmployeeFaceTemplate;
use App\Models\SfFieldCheck;
use App\Models\User;
use App\Models\UserEnterpriseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesSfPersonalFixtures;
use Tests\TestCase;

/**
 * Endpoints públicos de la app directa del checador de campo SF:
 * emparejar, paquete, sync, rescatar y desemparejar.
 */
class ChecadorCampoDispositivoTest extends TestCase
{
    use RefreshDatabase, CreatesSfPersonalFixtures;

    private const PASSWORD = 'password';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
    }

    private function empresaSf(): Enterprise
    {
        return Enterprise::where('slug', config('biometrics.sf_field_enterprise_slug'))->first()
            ?? Enterprise::create([
                'name' => 'Splendid Farms',
                'slug' => config('biometrics.sf_field_enterprise_slug'),
                'description' => 'SF',
                'is_active' => true,
            ]);
    }

    /** Usuario con (o sin) permiso del checador de campo en la empresa SF. */
    private function ingeniero(bool $conPermiso = true, array $overrides = []): User
    {
        $empresa = $this->empresaSf();
        $user = User::factory()->create(array_merge(['role' => 'user'], $overrides));
        UserEnterpriseAccess::create([
            'user_id' => $user->id,
            'enterprise_id' => $empresa->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);
        if ($conPermiso) {
            $this->darAccesoChecadorCampo($user, $empresa);
        }

        return $user;
    }

    /** @return array{0: string, 1: DevicePairing} */
    private function emparejarConAcceso(User $user, array $extra = []): array
    {
        $response = $this->postJson('/api/checador-campo/emparejar', array_merge([
            'email' => $user->email,
            'password' => self::PASSWORD,
        ], $extra));
        $response->assertStatus(201);

        return [
            $response->json('data.device_token'),
            DevicePairing::findOrFail($response->json('data.emparejamiento_id')),
        ];
    }

    private function conToken(string $raw): static
    {
        return $this->withHeaders(['X-Device-Token' => $raw]);
    }

    private function tinyJpegBase64(): string
    {
        return 'data:image/jpeg;base64,' . base64_encode(base64_decode(
            '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/2wBDAQMDAwQDBAgEBAgQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCdABmX/9k='
        ));
    }

    private function check(?string $uuid = null, array $extra = []): array
    {
        return array_merge([
            'client_uuid' => $uuid ?? (string) Str::uuid(),
            'type' => 'check_in',
            'checked_at' => now()->toIso8601String(),
            'device_synced_at' => now()->toIso8601String(),
            'evidence_photo' => $this->tinyJpegBase64(),
            'client_confidence' => 0.12,
            'manual_override' => false,
        ], $extra);
    }

    private function enrolar(int $employeeId): void
    {
        $path = "private/sf-face-templates/{$employeeId}.jpg";
        Storage::disk('local')->put($path, UploadedFile::fake()->image('face.jpg', 100, 100)->get());

        SfEmployeeFaceTemplate::create([
            'sf_employee_id' => $employeeId,
            'embedding' => array_fill(0, 128, 0.1),
            'photo_path' => $path,
            'model_version' => 'faceapi-v1',
            'enrolled_at' => now(),
            'consent_signed_at' => now(),
            'status' => SfEmployeeFaceTemplate::STATUS_ACTIVE,
        ]);
    }

    /** Celular de A revocado y celular nuevo de B, listos para rescatar. */
    private function escenarioRescate(): array
    {
        $a = $this->ingeniero();
        $b = $this->ingeniero();
        [$tokenA, $pairingA] = $this->emparejarConAcceso($a);
        $pairingA->update(['revoked_at' => now()]);
        [$tokenB, $pairingB] = $this->emparejarConAcceso($b);

        return [$a, $b, $tokenA, $pairingA, $tokenB, $pairingB];
    }

    // ---------------- emparejar ----------------

    public function test_emparejar_con_credenciales_malas_422(): void
    {
        $user = $this->ingeniero();

        $malPassword = $this->postJson('/api/checador-campo/emparejar', [
            'email' => $user->email,
            'password' => 'incorrecta',
        ]);
        $malPassword->assertStatus(422)
            ->assertJsonPath('code', 'credenciales_invalidas')
            ->assertJsonPath('message', 'Usuario o contraseña incorrectos.');

        // Mismo resultado si el correo no existe: no se revela cuál falló.
        $noExiste = $this->postJson('/api/checador-campo/emparejar', [
            'email' => 'nadie@example.com',
            'password' => 'incorrecta',
        ]);
        $noExiste->assertStatus(422)->assertJsonPath('code', 'credenciales_invalidas');
        $this->assertSame($malPassword->json(), $noExiste->json());

        $this->assertSame(0, DevicePairing::count());
    }

    public function test_emparejar_sin_permiso_403(): void
    {
        $user = $this->ingeniero(false);

        $this->postJson('/api/checador-campo/emparejar', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(403)->assertJsonPath('code', 'sin_permiso');

        $this->assertSame(0, DevicePairing::count());
    }

    public function test_emparejar_con_password_mala_no_revela_si_tiene_permiso(): void
    {
        $sinPermiso = $this->ingeniero(false);

        $this->postJson('/api/checador-campo/emparejar', [
            'email' => $sinPermiso->email,
            'password' => 'incorrecta',
        ])->assertStatus(422)->assertJsonPath('code', 'credenciales_invalidas');
    }

    public function test_emparejar_con_cambio_de_contrasena_pendiente_403(): void
    {
        $user = $this->ingeniero(true, ['must_change_password' => true]);

        $this->postJson('/api/checador-campo/emparejar', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(403)
            ->assertJsonPath('code', 'cambio_contrasena_requerido')
            ->assertJsonPath('message', 'Cambia tu contraseña en Sentinel antes de usar el checador.');

        $this->assertSame(0, DevicePairing::count());
    }

    public function test_emparejar_sin_empresa_sf_503(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->postJson('/api/checador-campo/emparejar', [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertStatus(503);
    }

    public function test_emparejar_crea_sf_field_y_devuelve_token_una_vez(): void
    {
        $user = $this->ingeniero();
        $empresa = $this->empresaSf();

        $response = $this->postJson('/api/checador-campo/emparejar', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'label' => 'Celular de campo 1',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.enterprise_id', $empresa->id)
            ->assertJsonPath('data.ingeniero.id', $user->id)
            ->assertJsonPath('data.ingeniero.name', $user->name);
        $token = $response->json('data.device_token');
        $this->assertNotEmpty($token);
        $this->assertNotEmpty($response->json('data.emparejado_en'));

        $pairing = DevicePairing::findOrFail($response->json('data.emparejamiento_id'));
        $this->assertSame(DevicePairing::MODE_SF_FIELD, $pairing->mode);
        $this->assertSame($user->id, $pairing->paired_by_user_id);
        $this->assertSame($empresa->id, $pairing->enterprise_id);
        $this->assertSame('Celular de campo 1', $pairing->label);
        $this->assertNotNull($pairing->last_used_at);
        $this->assertNull($pairing->revoked_at);

        // Solo se guarda el hash, nunca el token en claro.
        $this->assertNotSame($token, $pairing->device_token_hash);
        $this->assertSame(DevicePairing::hashToken($token), $pairing->device_token_hash);
        $this->assertSame(0, DB::table('device_pairings')->where('device_token_hash', $token)->count());

        // No crea sesión Sanctum.
        $this->assertSame(0, DB::table('personal_access_tokens')->count());

        // El token en claro no vuelve a aparecer en ninguna otra respuesta.
        $paquete = $this->conToken($token)->getJson('/api/checador-campo/paquete');
        $paquete->assertOk();
        $this->assertStringNotContainsString($token, $paquete->getContent());

        // Queda en la bitácora de actividad.
        $this->assertTrue(ActivityLog::where('model', 'DevicePairing')->where('model_id', $pairing->id)->where('action', 'create')->exists());
    }

    public function test_emparejar_con_token_anterior_revoca_el_anterior(): void
    {
        $a = $this->ingeniero();
        $b = $this->ingeniero();
        [$tokenA, $pairingA] = $this->emparejarConAcceso($a);

        [, $pairingB] = $this->emparejarConAcceso($b, ['token_anterior' => $tokenA]);

        $this->assertNotNull($pairingA->fresh()->revoked_at);
        $this->assertNull($pairingB->fresh()->revoked_at);

        $this->conToken($tokenA)->getJson('/api/checador-campo/paquete')
            ->assertStatus(401)->assertJsonPath('code', 'dispositivo_revocado');
    }

    public function test_emparejar_con_token_anterior_ya_revocado_o_desconocido_no_falla(): void
    {
        $a = $this->ingeniero();
        [$tokenA, $pairingA] = $this->emparejarConAcceso($a);
        $revocadoEn = now()->subDay();
        $pairingA->update(['revoked_at' => $revocadoEn]);

        $this->emparejarConAcceso($a, ['token_anterior' => $tokenA]);
        $this->assertSame(
            $revocadoEn->toDateTimeString(),
            $pairingA->fresh()->revoked_at->toDateTimeString(),
            'Un emparejamiento ya revocado conserva su fecha de revocación.'
        );

        $this->emparejarConAcceso($a, ['token_anterior' => 'token-que-no-existe']);
    }

    public function test_emparejar_no_revoca_otros_celulares_del_mismo_ingeniero(): void
    {
        $a = $this->ingeniero();
        [, $primero] = $this->emparejarConAcceso($a, ['label' => 'Celular 1']);
        [$tokenViejo, $segundo] = $this->emparejarConAcceso($a, ['label' => 'Celular 2']);

        // Un tercer celular reemplaza solo al segundo.
        [, $tercero] = $this->emparejarConAcceso($a, ['label' => 'Celular 3', 'token_anterior' => $tokenViejo]);

        $this->assertNull($primero->fresh()->revoked_at);
        $this->assertNotNull($segundo->fresh()->revoked_at);
        $this->assertNull($tercero->fresh()->revoked_at);
    }

    public function test_emparejar_con_token_anterior_de_ge_no_lo_revoca(): void
    {
        $a = $this->ingeniero();
        $raw = DevicePairing::generateToken();
        $ge = DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($raw),
            'mode' => DevicePairing::MODE_KIOSK,
            'paired_by_user_id' => User::factory()->create()->id,
        ]);

        $this->emparejarConAcceso($a, ['token_anterior' => $raw]);

        $this->assertNull($ge->fresh()->revoked_at);
    }

    public function test_emparejar_tiene_throttle_propio(): void
    {
        RateLimiter::clear('emparejar-campo');
        $user = $this->ingeniero();
        [$token] = $this->emparejarConAcceso($user); // 1.er intento

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/checador-campo/emparejar', [
                'email' => $user->email,
                'password' => 'incorrecta',
            ])->assertStatus(422);
        }

        $this->postJson('/api/checador-campo/emparejar', [
            'email' => $user->email,
            'password' => 'incorrecta',
        ])->assertStatus(429); // 6.º intento en 1 minuto

        // El límite de emparejar no consume el cupo de las demás rutas del grupo.
        $this->conToken($token)->getJson('/api/checador-campo/paquete')->assertOk();
        $this->conToken($token)->postJson('/api/checador-campo/sync', ['checks' => [$this->check()]])->assertOk();
    }

    // ---------------- paquete ----------------

    public function test_paquete_con_token_valido(): void
    {
        $user = $this->ingeniero();
        $empresa = $this->empresaSf();
        $empleado = $this->createSfEmployee($empresa->id);
        $this->enrolar($empleado->id);
        [$token] = $this->emparejarConAcceso($user);

        $response = $this->conToken($token)->getJson('/api/checador-campo/paquete');

        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.model_version', 'faceapi-v1');
        $this->assertSame([$empleado->id], collect($response->json('data.employees'))->pluck('id')->all());
    }

    public function test_paquete_sin_token_401(): void
    {
        $this->getJson('/api/checador-campo/paquete')
            ->assertStatus(401)->assertJsonPath('code', 'dispositivo_no_emparejado');
    }

    public function test_paquete_de_otra_empresa_no_se_mezcla(): void
    {
        $user = $this->ingeniero();
        $empresa = $this->empresaSf();
        $propio = $this->createSfEmployee($empresa->id);
        $this->enrolar($propio->id);

        $otra = Enterprise::create(['name' => 'Otra', 'slug' => 'otra-empresa', 'description' => 'x', 'is_active' => true]);
        $ajeno = $this->createSfEmployee($otra->id);
        $this->enrolar($ajeno->id);

        [$token] = $this->emparejarConAcceso($user);

        $ids = collect($this->conToken($token)->getJson('/api/checador-campo/paquete')->assertOk()->json('data.employees'))->pluck('id');

        $this->assertTrue($ids->contains($propio->id));
        $this->assertFalse($ids->contains($ajeno->id));
    }

    // ---------------- sync ----------------

    public function test_sync_atribuye_al_ingeniero_del_emparejamiento(): void
    {
        $user = $this->ingeniero();
        $empresa = $this->empresaSf();
        $empleado = $this->createSfEmployee($empresa->id);
        [$token, $pairing] = $this->emparejarConAcceso($user);
        $uuid = (string) Str::uuid();

        $response = $this->conToken($token)->postJson('/api/checador-campo/sync', [
            'checks' => [$this->check($uuid, ['sf_employee_id' => $empleado->id])],
        ]);

        $response->assertOk();
        $this->assertSame('accepted', collect($response->json('data.results'))->firstWhere('client_uuid', $uuid)['status']);

        $fila = SfFieldCheck::where('client_uuid', $uuid)->firstOrFail();
        $this->assertSame($user->id, $fila->checked_by_user_id);
        $this->assertSame($pairing->id, $fila->device_pairing_id);
        $this->assertSame($empresa->id, $fila->enterprise_id);
        $this->assertNull($fila->rescued_by_user_id);
        $this->assertNull($fila->review_reason);
        Queue::assertPushed(\App\Jobs\VerifyFieldCheckJob::class);
    }

    public function test_sync_es_idempotente_por_client_uuid(): void
    {
        $user = $this->ingeniero();
        [$token] = $this->emparejarConAcceso($user);
        $payload = ['checks' => [$this->check()]];

        $this->conToken($token)->postJson('/api/checador-campo/sync', $payload)->assertOk();
        $segundo = $this->conToken($token)->postJson('/api/checador-campo/sync', $payload)->assertOk();

        $this->assertSame('duplicate', $segundo->json('data.results.0.status'));
        $this->assertSame(1, SfFieldCheck::count());
    }

    public function test_sync_con_empleado_de_otra_empresa_se_rechaza_la_peticion(): void
    {
        $user = $this->ingeniero();
        $otra = Enterprise::create(['name' => 'Otra', 'slug' => 'otra-empresa', 'description' => 'x', 'is_active' => true]);
        $ajeno = $this->createSfEmployee($otra->id);
        [$token] = $this->emparejarConAcceso($user);

        $this->conToken($token)->postJson('/api/checador-campo/sync', [
            'checks' => [$this->check(null, ['sf_employee_id' => $ajeno->id])],
        ])->assertStatus(422);

        $this->assertSame(0, SfFieldCheck::count());
    }

    public function test_sync_con_token_revocado_401(): void
    {
        $user = $this->ingeniero();
        [$token, $pairing] = $this->emparejarConAcceso($user);
        $pairing->update(['revoked_at' => now()]);

        $this->conToken($token)->postJson('/api/checador-campo/sync', ['checks' => [$this->check()]])
            ->assertStatus(401)->assertJsonPath('code', 'dispositivo_revocado');
        $this->assertSame(0, SfFieldCheck::count());
    }

    // ---------------- rescatar ----------------

    public function test_rescate_atribuye_al_ingeniero_original_y_manda_a_revision(): void
    {
        [$a, $b, $tokenA, $pairingA, $tokenB, $pairingB] = $this->escenarioRescate();
        $uuid = (string) Str::uuid();

        $response = $this->conToken($tokenB)->postJson('/api/checador-campo/rescatar', [
            'token_anterior' => $tokenA,
            'checks' => [$this->check($uuid)],
        ]);

        $response->assertOk();
        $this->assertSame('accepted', collect($response->json('data.results'))->firstWhere('client_uuid', $uuid)['status']);

        $fila = SfFieldCheck::where('client_uuid', $uuid)->firstOrFail();
        $this->assertSame($a->id, $fila->checked_by_user_id);
        $this->assertSame($pairingA->id, $fila->device_pairing_id);
        $this->assertSame($b->id, $fila->rescued_by_user_id);
        $this->assertSame('dispositivo_revocado', $fila->review_reason);
        Queue::assertPushed(\App\Jobs\VerifyFieldCheckJob::class);
    }

    public function test_rescate_con_usuario_original_borrado(): void
    {
        [$a, $b, $tokenA, $pairingA, $tokenB] = $this->escenarioRescate();
        // Simula el borrado del usuario sin depender de las FK de sqlite.
        DB::table('device_pairings')->where('id', $pairingA->id)->update(['paired_by_user_id' => null]);
        $uuid = (string) Str::uuid();

        $this->conToken($tokenB)->postJson('/api/checador-campo/rescatar', [
            'token_anterior' => $tokenA,
            'checks' => [$this->check($uuid)],
        ])->assertOk();

        $fila = SfFieldCheck::where('client_uuid', $uuid)->firstOrFail();
        $this->assertNull($fila->checked_by_user_id);
        $this->assertSame($pairingA->id, $fila->device_pairing_id);
        $this->assertSame($b->id, $fila->rescued_by_user_id);
        $this->assertSame('dispositivo_revocado', $fila->review_reason);
    }

    public function test_rescate_acepta_token_anterior_caducado(): void
    {
        $a = $this->ingeniero();
        $b = $this->ingeniero();
        [$tokenA, $pairingA] = $this->emparejarConAcceso($a);
        $pairingA->update(['last_used_at' => now()->subDays(45)]);
        [$tokenB] = $this->emparejarConAcceso($b);
        $uuid = (string) Str::uuid();

        $this->conToken($tokenB)->postJson('/api/checador-campo/rescatar', [
            'token_anterior' => $tokenA,
            'checks' => [$this->check($uuid)],
        ])->assertOk();

        $this->assertSame('dispositivo_revocado', SfFieldCheck::where('client_uuid', $uuid)->value('review_reason'));
    }

    public function test_rescate_rechaza_token_anterior_vigente_o_de_ge(): void
    {
        $a = $this->ingeniero();
        $b = $this->ingeniero();
        [$tokenA] = $this->emparejarConAcceso($a); // sigue vigente
        [$tokenB, $pairingB] = $this->emparejarConAcceso($b);

        // Vigente.
        $this->conToken($tokenB)->postJson('/api/checador-campo/rescatar', [
            'token_anterior' => $tokenA,
            'checks' => [$this->check()],
        ])->assertStatus(422)->assertJsonPath('message', 'Ese celular sigue autorizado; sincroniza normal.');

        // De Grupo Espléndido (aunque esté revocado).
        $rawGe = DevicePairing::generateToken();
        DevicePairing::create([
            'device_token_hash' => DevicePairing::hashToken($rawGe),
            'mode' => DevicePairing::MODE_KIOSK,
            'paired_by_user_id' => User::factory()->create()->id,
            'revoked_at' => now(),
        ]);
        $this->conToken($tokenB)->postJson('/api/checador-campo/rescatar', [
            'token_anterior' => $rawGe,
            'checks' => [$this->check()],
        ])->assertStatus(422);

        // Desconocido.
        $this->conToken($tokenB)->postJson('/api/checador-campo/rescatar', [
            'token_anterior' => 'no-existe',
            'checks' => [$this->check()],
        ])->assertStatus(422);

        // El propio token actual.
        $this->conToken($tokenB)->postJson('/api/checador-campo/rescatar', [
            'token_anterior' => $tokenB,
            'checks' => [$this->check()],
        ])->assertStatus(422);

        // Sin token_anterior.
        $this->conToken($tokenB)->postJson('/api/checador-campo/rescatar', [
            'checks' => [$this->check()],
        ])->assertStatus(422);

        $this->assertSame(0, SfFieldCheck::count());
    }

    public function test_rescate_rechaza_token_anterior_de_otra_empresa(): void
    {
        [, , $tokenA, $pairingA, $tokenB] = $this->escenarioRescate();
        $otra = Enterprise::create(['name' => 'Otra', 'slug' => 'otra-empresa', 'description' => 'x', 'is_active' => true]);
        $pairingA->update(['enterprise_id' => $otra->id]);

        $this->conToken($tokenB)->postJson('/api/checador-campo/rescatar', [
            'token_anterior' => $tokenA,
            'checks' => [$this->check()],
        ])->assertStatus(422);

        $this->assertSame(0, SfFieldCheck::count());
    }

    public function test_rescate_requiere_el_token_nuevo_del_dispositivo(): void
    {
        [, , $tokenA] = $this->escenarioRescate();

        // El token viejo (revocado) no autentica el rescate.
        $this->conToken($tokenA)->postJson('/api/checador-campo/rescatar', [
            'token_anterior' => $tokenA,
            'checks' => [$this->check()],
        ])->assertStatus(401)->assertJsonPath('code', 'dispositivo_revocado');

        $this->postJson('/api/checador-campo/rescatar', [
            'token_anterior' => $tokenA,
            'checks' => [$this->check()],
        ])->assertStatus(401);
    }

    public function test_rescate_es_idempotente_por_client_uuid(): void
    {
        [, , $tokenA, , $tokenB] = $this->escenarioRescate();
        $payload = ['token_anterior' => $tokenA, 'checks' => [$this->check()]];

        $this->conToken($tokenB)->postJson('/api/checador-campo/rescatar', $payload)->assertOk();
        $segundo = $this->conToken($tokenB)->postJson('/api/checador-campo/rescatar', $payload)->assertOk();

        $this->assertSame('duplicate', $segundo->json('data.results.0.status'));
        $this->assertSame(1, SfFieldCheck::count());
    }

    // ---------------- desemparejar ----------------

    public function test_desemparejar_revoca(): void
    {
        $user = $this->ingeniero();
        [$token, $pairing] = $this->emparejarConAcceso($user);
        [, $otro] = $this->emparejarConAcceso($user);

        $this->conToken($token)->postJson('/api/checador-campo/desemparejar')->assertOk();

        $this->assertNotNull($pairing->fresh()->revoked_at);
        $this->assertNull($otro->fresh()->revoked_at, 'Solo se revoca el propio celular.');
        $this->conToken($token)->getJson('/api/checador-campo/paquete')
            ->assertStatus(401)->assertJsonPath('code', 'dispositivo_revocado');
    }

    // ---------------- convivencia con las rutas Sanctum ----------------

    public function test_rutas_sanctum_actuales_siguen_igual(): void
    {
        [$user, $enterprise] = $this->createAuthenticatedUserWithEnterprise();
        $empleado = $this->createSfEmployee($enterprise->id);
        $this->enrolar($empleado->id);
        Sanctum::actingAs($user);

        $this->getJson("/api/splendidfarms/administration/personal/field-checks/crew-package?enterprise_id={$enterprise->id}")
            ->assertOk();

        $uuid = (string) Str::uuid();
        $this->postJson('/api/splendidfarms/administration/personal/field-checks/sync', [
            'enterprise_id' => $enterprise->id,
            'checks' => [$this->check($uuid)],
        ])->assertOk();

        $fila = SfFieldCheck::where('client_uuid', $uuid)->firstOrFail();
        $this->assertSame($user->id, $fila->checked_by_user_id);
        $this->assertNull($fila->device_pairing_id);
        $this->assertNull($fila->review_reason);
    }

    public function test_un_token_sanctum_no_abre_las_rutas_del_dispositivo(): void
    {
        [$user] = $this->createAuthenticatedUserWithEnterprise();
        Sanctum::actingAs($user);

        $this->getJson('/api/checador-campo/paquete')->assertStatus(401);
    }
}
