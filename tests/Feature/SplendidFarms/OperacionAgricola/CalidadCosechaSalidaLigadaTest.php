<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola;

use App\Models\CalidadCosecha;
use App\Models\SalidaCampoCosecha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesReporteEmpaqueFixtures;
use Tests\TestCase;

/**
 * Calidad de cosecha con una salida ligada: el select de la relación
 * salidaCampo solo puede pedir columnas que existen en salidas_campo_cosecha
 * (producto y unidad_medida se eliminaron en el rediseño de marzo).
 */
class CalidadCosechaSalidaLigadaTest extends TestCase
{
    use CreatesReporteEmpaqueFixtures;
    use RefreshDatabase;

    private const URL = '/api/splendidfarms/operacion-agricola/cosecha/calidad';

    private CalidadCosecha $calidad;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpReporteEmpaqueFixtures();
        Sanctum::actingAs($this->actingUser);

        $salida = SalidaCampoCosecha::create([
            'temporada_id' => $this->temporada->id,
            'fecha' => '2026-02-10',
            'cantidad' => 10,
            'peso_neto_kg' => 200,
            'tipo_carga_id' => $this->tipoCarga->id,
            'folio_salida' => 'SAL-CAL-001',
        ]);

        $this->calidad = CalidadCosecha::create([
            'temporada_id' => $this->temporada->id,
            'salida_campo_cosecha_id' => $salida->id,
            'fecha_inspeccion' => '2026-02-10',
            'tipo_inspeccion' => 'visual',
            'resultado' => 'aprobado',
            'created_by' => $this->actingUser->id,
        ]);
    }

    public function test_listado_de_calidad_con_salida_ligada_responde_200(): void
    {
        $index = $this->getJson(self::URL.'?temporada_id='.$this->temporada->id);
        $index->assertOk()
            ->assertJsonPath('data.0.salida_campo.folio_salida', 'SAL-CAL-001')
            ->assertJsonPath('data.0.salida_campo.peso_neto_kg', '200.00')
            ->assertJsonPath('data.0.salida_campo.cantidad', 10);

        // Solo columnas reales: en SQLite un identificador entre comillas que no
        // existe se vuelve texto literal (clave "producto" con valor "producto");
        // en MySQL ese mismo select revienta con "Unknown column".
        $claves = array_keys($index->json('data.0.salida_campo'));
        $this->assertEqualsCanonicalizing(
            ['id', 'folio_salida', 'fecha', 'cantidad', 'peso_neto_kg', 'tipo_carga_id', 'peso_calculado', 'tipo_carga'],
            $claves
        );

        $show = $this->getJson(self::URL.'/'.$this->calidad->id);
        $show->assertOk()
            ->assertJsonPath('data.salida_campo.folio_salida', 'SAL-CAL-001');
    }

    public function test_actualizar_calidad_con_salida_ligada_responde_200(): void
    {
        $this->putJson(self::URL.'/'.$this->calidad->id, ['observaciones' => 'Revisada'])
            ->assertOk()
            ->assertJsonPath('data.salida_campo.folio_salida', 'SAL-CAL-001');
    }

    public function test_crear_calidad_con_salida_ligada_responde_201(): void
    {
        $this->postJson(self::URL, [
            'temporada_id' => $this->temporada->id,
            'salida_campo_cosecha_id' => $this->calidad->salida_campo_cosecha_id,
            'fecha_inspeccion' => '2026-02-11',
            'tipo_inspeccion' => 'visual',
            'resultado' => 'aprobado',
        ])
            ->assertCreated()
            ->assertJsonPath('data.salida_campo.folio_salida', 'SAL-CAL-001');
    }
}
