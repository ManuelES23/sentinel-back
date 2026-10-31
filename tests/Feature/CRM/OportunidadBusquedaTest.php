<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Models\CRM\CrmVendedor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * `GET crm/oportunidades?search=&abiertas=1`: búsqueda por nombre (de la
 * oportunidad, del cliente o del prospecto) y solo abiertas, sin cambiar el
 * comportamiento por defecto ni las reglas de visibilidad.
 */
class OportunidadBusquedaTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const URL = '/api/crm/oportunidades';

    private CrmVendedor $propio;

    private CrmCliente $cliente;

    private CrmProspecto $prospecto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['ver']);

        $this->propio = CrmVendedor::create([
            'empresa_id' => $this->enterprise->id, 'user_id' => $this->actingUser->id,
            'nombre' => 'Vendedor del usuario', 'activo' => true,
        ]);
        $this->cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Agrícola Delta', 'estatus' => 'activo']);
        $this->prospecto = CrmProspecto::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Rancho Sol', 'estatus' => 'nuevo']);
    }

    private function crear(string $nombre, string $etapa = 'calificado', array $extra = []): CrmOportunidad
    {
        return CrmOportunidad::create($extra + [
            'empresa_id' => $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->propio->id, 'nombre' => $nombre, 'etapa' => $etapa,
        ]);
    }

    private function listar(array $query = [])
    {
        return $this->withHeaders($this->crmHeaders())->getJson(self::URL.'?'.http_build_query($query));
    }

    private function nombres($respuesta): array
    {
        return collect($respuesta->json('data'))->pluck('nombre')->sort()->values()->all();
    }

    public function test_busca_por_nombre_de_la_oportunidad_sin_distinguir_mayusculas(): void
    {
        $this->crear('Venta de mango');
        $this->crear('Venta de maíz');

        $this->assertSame(['Venta de mango'], $this->nombres($this->listar(['search' => 'MANGO'])));
    }

    public function test_busca_por_nombre_del_cliente(): void
    {
        $otro = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Otro Cliente', 'estatus' => 'activo']);
        $this->crear('Uno');
        $this->crear('Dos', extra: ['cliente_id' => $otro->id]);

        $this->assertSame(['Uno'], $this->nombres($this->listar(['search' => 'delta'])));
    }

    public function test_busca_por_nombre_del_prospecto(): void
    {
        $this->crear('Uno');
        $this->crear('Dos', extra: ['cliente_id' => null, 'prospecto_id' => $this->prospecto->id]);

        $this->assertSame(['Dos'], $this->nombres($this->listar(['search' => 'rancho'])));
    }

    public function test_porcentaje_y_guion_bajo_se_buscan_literalmente(): void
    {
        $this->crear('Descuento 50% anual');
        $this->crear('Descuento 500 anual');
        $this->crear('Plan_A');
        $this->crear('PlanXA');

        $this->assertSame(['Descuento 50% anual'], $this->nombres($this->listar(['search' => '50%'])));
        $this->assertSame(['Plan_A'], $this->nombres($this->listar(['search' => 'n_a'])));
        // Un % suelto solo encuentra nombres que lo contienen.
        $this->assertSame(['Descuento 50% anual'], $this->nombres($this->listar(['search' => '%'])));
    }

    public function test_un_search_no_string_se_ignora(): void
    {
        $this->crear('Uno');
        $this->crear('Dos');

        $this->assertSame(['Dos', 'Uno'], $this->nombres($this->listar(['search' => ['zzz']])));
    }

    public function test_un_search_en_blanco_no_filtra(): void
    {
        $this->crear('Uno');

        $this->assertSame(['Uno'], $this->nombres($this->listar(['search' => '   '])));
    }

    public function test_search_se_recorta_a_100_caracteres(): void
    {
        $largo = str_repeat('a', 100);
        $this->crear($largo);

        // Lo que pase de 100 se descarta: el nombre de 100 caracteres sigue apareciendo.
        $this->assertSame([$largo], $this->nombres($this->listar(['search' => $largo.str_repeat('z', 200)])));
    }

    public function test_abiertas_excluye_ganadas_y_perdidas(): void
    {
        $this->crear('Abierta', 'propuesta');
        $this->crear('Ganada', 'cerrado_ganado');
        $this->crear('Perdida', 'cerrado_perdido');

        $this->assertSame(['Abierta'], $this->nombres($this->listar(['abiertas' => 1])));
        // Sin el parámetro (o en 0) todo sigue igual que antes.
        $this->assertSame(['Abierta', 'Ganada', 'Perdida'], $this->nombres($this->listar()));
        $this->assertSame(['Abierta', 'Ganada', 'Perdida'], $this->nombres($this->listar(['abiertas' => 0])));
    }

    public function test_search_y_abiertas_juntos_y_con_otros_filtros(): void
    {
        $this->crear('Mango abierta', 'negociacion');
        $this->crear('Mango ganada', 'cerrado_ganado');
        $this->crear('Mango calificada', 'calificado');
        $this->crear('Maíz abierta', 'negociacion');

        $this->assertSame(['Mango abierta', 'Mango calificada'], $this->nombres($this->listar(['search' => 'mango', 'abiertas' => 1])));
        $this->assertSame(['Mango abierta'], $this->nombres($this->listar([
            'search' => 'mango', 'abiertas' => 1, 'etapa' => 'negociacion', 'cliente_id' => $this->cliente->id,
        ])));
    }

    public function test_sin_equipo_nunca_devuelve_las_de_otro_vendedor(): void
    {
        $this->crear('Mango propia');
        $this->crear('Mango ajena', extra: ['vendedor_id' => $this->vendedor->id]);

        $this->assertSame(['Mango propia'], $this->nombres($this->listar(['search' => 'mango', 'abiertas' => 1])));

        $this->listar(['search' => 'mango', 'vendedor_id' => $this->vendedor->id])->assertForbidden();
    }

    public function test_con_equipo_ve_las_de_todos(): void
    {
        $this->otorgarPermisosCrm('oportunidades', 'oportunidades', ['equipo']);
        $this->crear('Mango propia');
        $this->crear('Mango ajena', extra: ['vendedor_id' => $this->vendedor->id]);

        $this->assertSame(['Mango ajena', 'Mango propia'], $this->nombres($this->listar(['search' => 'mango'])));
    }

    public function test_la_paginacion_se_conserva(): void
    {
        foreach (range(1, 5) as $i) {
            $this->crear("Mango $i");
        }
        $this->crear('Maíz');

        $respuesta = $this->listar(['search' => 'mango', 'abiertas' => 1, 'per_page' => 2])->assertOk();

        $this->assertCount(2, $respuesta->json('data'));
        $this->assertSame(5, $respuesta->json('meta.total'));
        $this->assertSame(3, $respuesta->json('meta.last_page'));
    }
}
