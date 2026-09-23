<?php

namespace Tests\Feature\Compras;

use App\Models\SystemNotification;
use App\Models\User;
use App\Services\Compras\AvisosCompras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class AvisosComprasUrlTest extends TestCase
{
    use CreatesComprasFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
    }

    public function test_el_aviso_de_requisicion_enviada_apunta_a_compras_en_administracion(): void
    {
        $comprador = User::factory()->create();
        $this->otorgarCotizar($comprador);

        $requisicion = $this->crearRequisicion($this->crearUsuarioDeCampo([$this->almacenA]), $this->almacenA, 'enviada');
        app(AvisosCompras::class)->requisicionEnviada($requisicion);

        $this->assertDatabaseHas('system_notifications', [
            'user_id' => $comprador->id,
            'action_url' => '/splendidfarms/administration/compras/requisiciones',
        ]);
    }
}
