<?php

namespace Tests\Feature\Compras;

use App\Models\SystemNotification;
use App\Models\User;
use App\Services\Compras\AprobadorOrdenCompra;
use App\Services\Compras\AvisosCompras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesComprasFixtures;
use Tests\TestCase;

class AprobadorOrdenCompraTest extends TestCase
{
    use RefreshDatabase, CreatesComprasFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpComprasFixtures();
    }

    public function test_sin_flujo_no_hay_aprobadores(): void
    {
        $this->assertFalse(app(AprobadorOrdenCompra::class)->hayAprobadores($this->empresa->id));
    }

    public function test_aprobador_de_empresa_puede_aprobar_pero_no_la_suya(): void
    {
        $gerente = $this->crearAprobador('enterprise');
        $aprobador = app(AprobadorOrdenCompra::class);
        $oc = $this->crearOrden(['status' => 'pending']);

        $this->assertTrue($aprobador->hayAprobadores($this->empresa->id));
        $this->assertTrue($aprobador->puedeAprobar($gerente, $oc));
        $this->assertFalse($aprobador->puedeAprobar(User::factory()->create(), $oc));

        $propia = $this->crearOrden(['status' => 'pending', 'created_by' => $gerente->id]);
        $this->assertFalse($aprobador->puedeAprobar($gerente, $propia));
        $this->assertSame([$gerente->id], $aprobador->aprobadoresDe($oc)->pluck('id')->all());
    }

    public function test_alcance_de_departamento_requiere_creador_del_mismo_departamento(): void
    {
        $gerente = $this->crearAprobador('own_department');
        $oc = $this->crearOrden(['status' => 'pending']); // creador sin empleado

        $this->assertFalse(app(AprobadorOrdenCompra::class)->puedeAprobar($gerente, $oc));
    }

    public function test_avisos_de_orden(): void
    {
        $gerente = $this->crearAprobador('enterprise');
        $oc = $this->crearOrden(['status' => 'pending']);
        $avisos = app(AvisosCompras::class);

        $avisos->ordenPorAutorizar($oc);
        $this->assertSame(1, SystemNotification::where('user_id', $gerente->id)->count());

        $avisos->ordenResuelta($oc, true);
        $this->assertSame(1, SystemNotification::where('user_id', $oc->created_by)->where('title', 'like', '%aprobada%')->count());
    }
}
