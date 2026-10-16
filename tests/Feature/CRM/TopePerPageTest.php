<?php

namespace Tests\Feature\CRM;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Los listados paginados del CRM acotan `per_page` a 1..200: antes
 * `per_page=-1` (o uno enorme) devolvía la tabla completa de la empresa.
 */
class TopePerPageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
    }

    /** Un listado por controlador que antes aceptaba per_page sin tope. */
    public static function listados(): array
    {
        return [
            'actividades' => ['/api/crm/actividades', 50],
            'bodegas' => ['/api/crm/bodegas', 50],
            'clientes' => ['/api/crm/clientes', 15],
            'contactos' => ['/api/crm/contactos', 50],
            'cotizaciones (empresa)' => ['/api/crm/cotizaciones', 100],
            'dialpad llamadas' => ['/api/crm/integraciones/dialpad/llamadas', 25],
            'empresas externas' => ['/api/crm/empresas-externas', 15],
            'oportunidades' => ['/api/crm/oportunidades', 100],
            'productos' => ['/api/crm/productos', 50],
            'prospectos' => ['/api/crm/prospectos', 20],
            'regiones' => ['/api/crm/regiones', 50],
            'vendedores' => ['/api/crm/vendedores', 50],
            'zonas' => ['/api/crm/zonas', 50],
        ];
    }

    private function perPage(string $url, ?string $valor): int
    {
        $ruta = $valor === null ? $url : $url.'?per_page='.$valor;
        $response = $this->withHeaders($this->crmHeaders())->getJson($ruta);
        $response->assertOk();

        return (int) $response->json('meta.per_page');
    }

    /**
     * @dataProvider listados
     */
    public function test_per_page_negativo_cero_o_enorme_se_acota(string $url, int $porDefecto): void
    {
        $this->assertSame(1, $this->perPage($url, '-1'), 'per_page=-1 debe acotarse a 1');
        $this->assertSame(1, $this->perPage($url, '0'), 'per_page=0 debe acotarse a 1');
        $this->assertSame(200, $this->perPage($url, '100000'), 'per_page enorme debe acotarse a 200');
    }

    /**
     * @dataProvider listados
     */
    public function test_per_page_valido_se_respeta_y_sin_el_usa_el_valor_por_defecto(string $url, int $porDefecto): void
    {
        $this->assertSame(25, $this->perPage($url, '25'));
        $this->assertSame(200, $this->perPage($url, '200'));
        $this->assertSame($porDefecto, $this->perPage($url, null));
        $this->assertSame($porDefecto, $this->perPage($url, 'abc'), 'un valor no numérico cae al valor por defecto');
    }
}
