<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\User;
use App\Models\VacationOutlookConnection;
use App\Models\VacationOutlookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

/** Esquema y modelos de la conexión de Outlook de quien autoriza vacaciones (por usuario). */
class OutlookVacacionesEsquemaTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
    }

    private function conexion(User $usuario): VacationOutlookConnection
    {
        return VacationOutlookConnection::create([
            'user_id' => $usuario->id,
            'email_outlook' => 'director@example.com',
            'access_token' => 'token-acceso-en-claro',
            'refresh_token' => 'token-refresco-en-claro',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    private function solicitudAprobada()
    {
        return $this->solicitud($this->empleado($this->empresa), 'approved', 5);
    }

    public function test_la_conexion_cifra_y_oculta_los_tokens(): void
    {
        $conexion = $this->conexion(User::factory()->create());

        $fila = DB::table('vacation_outlook_connections')->where('id', $conexion->id)->first();
        $this->assertNotSame('token-acceso-en-claro', $fila->access_token);
        $this->assertNotSame('token-refresco-en-claro', $fila->refresh_token);

        $this->assertSame('token-acceso-en-claro', $conexion->fresh()->access_token);
        $this->assertSame('token-refresco-en-claro', $conexion->fresh()->refresh_token);

        $arreglo = $conexion->fresh()->toArray();
        $this->assertArrayNotHasKey('access_token', $arreglo);
        $this->assertArrayNotHasKey('refresh_token', $arreglo);
    }

    public function test_un_usuario_tiene_una_sola_conexion(): void
    {
        $usuario = User::factory()->create();
        $this->conexion($usuario);

        $this->expectException(QueryException::class);
        $this->conexion($usuario);
    }

    public function test_un_evento_por_conexion_y_solicitud(): void
    {
        $conexion = $this->conexion(User::factory()->create());
        $solicitud = $this->solicitudAprobada();

        $conexion->eventos()->create(['vacation_request_id' => $solicitud->id, 'outlook_event_id' => 'AAA', 'enviado_at' => now()]);

        $this->expectException(QueryException::class);
        $conexion->eventos()->create(['vacation_request_id' => $solicitud->id, 'outlook_event_id' => 'BBB', 'enviado_at' => now()]);
    }

    public function test_borrar_la_conexion_borra_sus_eventos(): void
    {
        $conexion = $this->conexion(User::factory()->create());
        $solicitud = $this->solicitudAprobada();
        $evento = $conexion->eventos()->create(['vacation_request_id' => $solicitud->id, 'outlook_event_id' => 'AAA', 'enviado_at' => now()]);

        $this->assertSame($conexion->id, $evento->conexion->id);
        $this->assertSame($solicitud->id, $evento->solicitud->id);

        $conexion->delete();

        $this->assertSame(0, VacationOutlookEvent::count());
    }
}
