<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Si el servidor de websockets está apagado o mal configurado, el CRM no debe
 * convertir eso en un 500: el registro ya se guardó y la pantalla que hizo el
 * cambio se refresca por su cuenta. Antes, un Reverb caído hacía fallar el
 * guardado de un vendedor, un cliente o una oportunidad DESPUÉS de haberlos
 * escrito en la base.
 */
class BroadcastToleranteTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();

        $this->simularWebsocketsCaidos();
    }

    /** Conecta el broadcasting a un driver que siempre revienta. */
    private function simularWebsocketsCaidos(): void
    {
        Broadcast::extend('reventando', fn () => new class implements Broadcaster
        {
            public function auth($request)
            {
                return true;
            }

            public function validAuthenticationResponse($request, $result)
            {
                return $result;
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                throw new RuntimeException('Pusher error: connection refused');
            }
        });

        config(['broadcasting.default' => 'reventando']);
    }

    public function test_crear_un_vendedor_no_falla_si_los_websockets_estan_caidos(): void
    {
        $respuesta = $this->withHeaders($this->crmHeaders())
            ->postJson('/api/crm/vendedores', ['nombre' => 'Vendedor sin websockets']);

        $respuesta->assertCreated();
        $this->assertDatabaseHas('crm_vendedores', ['nombre' => 'Vendedor sin websockets']);
    }

    public function test_crear_un_cliente_no_falla_si_los_websockets_estan_caidos(): void
    {
        $respuesta = $this->withHeaders($this->crmHeaders())
            ->postJson('/api/crm/clientes', ['nombre' => 'Cliente sin websockets']);

        $respuesta->assertCreated();
        $this->assertDatabaseHas('crm_clientes', ['nombre' => 'Cliente sin websockets']);
    }

    public function test_editar_un_cliente_no_falla_si_los_websockets_estan_caidos(): void
    {
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente original',
        ]);

        $respuesta = $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/clientes/{$cliente->id}", ['nombre' => 'Cliente renombrado']);

        $respuesta->assertOk();
        $this->assertDatabaseHas('crm_clientes', ['id' => $cliente->id, 'nombre' => 'Cliente renombrado']);
    }

    public function test_eliminar_un_cliente_no_falla_si_los_websockets_estan_caidos(): void
    {
        $cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id,
            'nombre' => 'Cliente a borrar',
        ]);

        $respuesta = $this->withHeaders($this->crmHeaders())
            ->deleteJson("/api/crm/clientes/{$cliente->id}");

        $respuesta->assertOk();
    }

    /**
     * Guarda de regresión para los 45 puntos que se migraron a `difundir()`:
     * un `broadcast()` suelto vuelve a exponer el 500 de arriba, y es fácil
     * copiarlo al escribir un controlador nuevo.
     */
    public function test_ningun_controlador_del_crm_emite_eventos_sin_proteger(): void
    {
        $sueltos = [];

        foreach (glob(app_path('Http/Controllers/Api/CRM/*.php')) as $archivo) {
            foreach (file($archivo) as $numero => $linea) {
                if (preg_match('/^\s*(broadcast\(|event\(new)/', $linea)) {
                    $sueltos[] = basename($archivo).':'.($numero + 1).' → '.trim($linea);
                }
            }
        }

        $this->assertSame([], $sueltos, "Estos eventos del CRM no pasan por difundir():\n".implode("\n", $sueltos));
    }
}
