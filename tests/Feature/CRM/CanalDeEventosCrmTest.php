<?php

namespace Tests\Feature\CRM;

use App\Events\CRM\ClienteUpdated;
use App\Events\CRM\OportunidadUpdated;
use App\Events\CRM\VendedorUpdated;
use App\Models\Enterprise;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El canal de un evento del CRM se decide con el registro, no con las
 * cabeceras que mandó el cliente: si el front manda otro X-Module-Slug, el
 * evento acababa en un canal donde nadie escucha y la pantalla se quedaba
 * vieja sin error visible.
 */
class CanalDeEventosCrmTest extends TestCase
{
    use RefreshDatabase;

    private Enterprise $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->empresa = Enterprise::create([
            'name' => 'Splendid Farms',
            'slug' => 'splendidfarms',
            'description' => 'Empresa de prueba',
            'is_active' => true,
        ]);
    }

    private function nombreDeCanal(object $evento): string
    {
        return $evento->broadcastOn()[0]->name;
    }

    public function test_el_canal_sale_del_registro_y_no_de_las_cabeceras(): void
    {
        request()->headers->set('X-Enterprise-Slug', 'empresa-equivocada');
        request()->headers->set('X-Module-Slug', 'modulo-equivocado');

        $evento = new ClienteUpdated('created', ['id' => 1, 'empresa_id' => $this->empresa->id]);

        $this->assertSame('private-module.splendidfarms.crm.clientes', $this->nombreDeCanal($evento));
    }

    public function test_los_eventos_de_catalogos_van_al_canal_catalogos(): void
    {
        // El front escucha vendedor/zona/región/bodega/producto en el canal
        // `catalogos`, no en uno por submódulo.
        $evento = new VendedorUpdated('updated', ['id' => 3, 'empresa_id' => $this->empresa->id]);

        $this->assertSame('private-module.splendidfarms.crm.catalogos', $this->nombreDeCanal($evento));
    }

    /**
     * Regresión: OportunidadUpdated emitía en un canal PÚBLICO y con el id
     * numérico de la empresa (`module.1.crm.oportunidades`), mientras el front
     * se suscribe a `private-module.{slug}.crm.oportunidades`. Nunca llegaba:
     * ni el tablero Kanban ni el listado se movían solos.
     */
    public function test_oportunidades_emite_en_el_canal_privado_con_el_slug(): void
    {
        $evento = new OportunidadUpdated('updated', ['id' => 7, 'empresa_id' => $this->empresa->id]);

        $canal = $evento->broadcastOn()[0];

        $this->assertInstanceOf(PrivateChannel::class, $canal);
        $this->assertSame('private-module.splendidfarms.crm.oportunidades', $canal->name);
    }

    public function test_todos_los_eventos_del_crm_usan_canales_privados(): void
    {
        $publicos = [];

        foreach (glob(app_path('Events/CRM/*.php')) as $archivo) {
            $contenido = file_get_contents($archivo);
            if (preg_match('/new Channel\(/', $contenido)) {
                $publicos[] = basename($archivo);
            }
        }

        $this->assertSame([], $publicos, 'Estos eventos del CRM emiten en canales públicos: '.implode(', ', $publicos));
    }

    public function test_sin_empresa_en_el_payload_cae_a_la_cabecera(): void
    {
        request()->headers->set('X-Enterprise-Slug', 'splendidfarms');

        $evento = new ClienteUpdated('deleted', ['id' => 9]);

        $this->assertSame('private-module.splendidfarms.crm.clientes', $this->nombreDeCanal($evento));
    }
}
