<?php

namespace Tests\Feature\SplendidFarms\OperacionAgricola\EloteBins;

use App\Models\SalidaRezagaEmpaque;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesEloteBinsFixtures;
use Tests\TestCase;

/**
 * Defensa en profundidad de los bins de rezaga: no sirven como tipo de carga
 * de salidas de campo ni de recepciones, su clase no cambia cuando ya tienen
 * registros ligados y la venta por bin tiene topes (422, no 500).
 */
class EndurecimientoTest extends TestCase
{
    use CreatesEloteBinsFixtures;
    use RefreshDatabase;

    private const MENSAJE_REZAGA = 'Ese tipo de carga es un bin de rezaga; elige un bin de campo.';
    private const MENSAJE_LIGADOS = 'Este tipo de carga ya tiene registros ligados; no se puede cambiar su clase de rezaga.';

    private const URL_SALIDAS = '/api/splendidfarms/operacion-agricola/cosecha/salidas-campo';
    private const URL_RECEPCIONES = '/api/splendidfarms/operacion-agricola/empaque/recepciones';
    private const URL_TIPOS = '/api/splendidfarms/inventario/catalogos/tipos-carga';
    private const URL_REZAGA = '/api/splendidfarms/operacion-agricola/empaque/salida-rezaga';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpEloteBinsFixtures();
        $this->concederPermisosSalidaRezaga();
        Sanctum::actingAs($this->actingUser);
    }

    private function cuerpoSalida(array $extra = []): array
    {
        return array_merge([
            'temporada_id' => $this->temporadaBins->id,
            'variedad_id' => $this->variedadElote->id,
            'lote_id' => $this->lote->id,
            'tipo_carga_id' => $this->binCampo->id,
            'productor_id' => $this->productorPrincipal->id,
            'fecha' => '2026-02-05',
            'cantidad' => 4,
            'vehiculo' => 'Camión 1',
        ], $extra);
    }

    private function cuerpoRecepcion(array $extra = []): array
    {
        return array_merge([
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'fecha_recepcion' => '2026-02-05',
            'productor_id' => $this->productorPrincipal->id,
            'lote_id' => $this->lote->id,
            'variedad_id' => $this->variedadElote->id,
            'tipo_carga_id' => $this->binCampo->id,
            'cantidad_recibida' => 4,
        ], $extra);
    }

    private function cuerpoVenta(array $extra = []): array
    {
        return array_merge([
            'temporada_id' => $this->temporadaBins->id,
            'entity_id' => $this->entity->id,
            'tipo_salida' => 'venta',
            'fecha_venta' => '2026-02-10',
            'comprador' => 'Forrajera del Valle',
            'precio_bin' => 100,
            'autorizado_por' => 'Gerente',
            'solicitado_por' => 'Comprador',
            'chofer' => 'Juan Pérez',
            'placa' => 'ABC-123',
        ], $extra);
    }

    private function crearTipoSinClase(string $nombre): int
    {
        return $this->postJson(self::URL_TIPOS, [
            'cultivo_id' => $this->cultivoElote->id,
            'nombre' => $nombre,
            'categoria_caja' => 'campo',
            'peso_estimado_kg' => 200,
        ])->assertCreated()->json('data.id');
    }

    // (a) tipo_carga_id solo acepta bins de campo

    public function test_salida_de_campo_rechaza_un_bin_de_rezaga(): void
    {
        $this->postJson(self::URL_SALIDAS, $this->cuerpoSalida(['tipo_carga_id' => $this->binHoja->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo_carga_id')
            ->assertJsonPath('errors.tipo_carga_id.0', self::MENSAJE_REZAGA);
    }

    public function test_salida_de_campo_acepta_un_bin_sin_clase(): void
    {
        $this->postJson(self::URL_SALIDAS, $this->cuerpoSalida())
            ->assertCreated();
    }

    public function test_salida_de_campo_inexistente_conserva_el_mensaje_por_defecto(): void
    {
        $r = $this->postJson(self::URL_SALIDAS, $this->cuerpoSalida(['tipo_carga_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo_carga_id');
        $this->assertNotSame(self::MENSAJE_REZAGA, $r->json('errors.tipo_carga_id.0'));
    }

    public function test_salida_de_campo_edicion_rechaza_un_bin_de_rezaga(): void
    {
        $id = $this->postJson(self::URL_SALIDAS, $this->cuerpoSalida())->assertCreated()->json('data.id');

        $this->putJson(self::URL_SALIDAS.'/'.$id, ['tipo_carga_id' => $this->binFresco->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.tipo_carga_id.0', self::MENSAJE_REZAGA);
    }

    public function test_recepcion_rechaza_un_bin_de_rezaga(): void
    {
        $this->postJson(self::URL_RECEPCIONES, $this->cuerpoRecepcion(['tipo_carga_id' => $this->binCuartoFrio->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo_carga_id')
            ->assertJsonPath('errors.tipo_carga_id.0', self::MENSAJE_REZAGA);
    }

    public function test_recepcion_acepta_un_bin_sin_clase(): void
    {
        $this->postJson(self::URL_RECEPCIONES, $this->cuerpoRecepcion())
            ->assertCreated();
    }

    public function test_recepcion_edicion_rechaza_un_bin_de_rezaga(): void
    {
        $proceso = $this->crearProcesoBins(5);

        $this->putJson(self::URL_RECEPCIONES.'/'.$proceso->recepcion_id, ['tipo_carga_id' => $this->binHoja->id])
            ->assertStatus(422)
            ->assertJsonPath('errors.tipo_carga_id.0', self::MENSAJE_REZAGA);
    }

    // (b) la clase de un bin con registros ligados no cambia

    public function test_no_se_cambia_la_clase_de_un_bin_con_rezagas(): void
    {
        $this->crearRezagaBins($this->crearProcesoBins(10));

        $url = self::URL_TIPOS.'/'.$this->binHoja->id;

        $this->putJson($url, ['clase_rezaga' => 'fresco'])
            ->assertStatus(422)
            ->assertJsonPath('errors.clase_rezaga.0', self::MENSAJE_LIGADOS);
        $this->putJson($url, ['clase_rezaga' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.clase_rezaga.0', self::MENSAJE_LIGADOS);

        $this->assertSame('hoja', $this->binHoja->fresh()->clase_rezaga);
    }

    public function test_misma_clase_con_registros_no_da_error(): void
    {
        $this->crearRezagaBins($this->crearProcesoBins(10));

        $url = self::URL_TIPOS.'/'.$this->binHoja->id;

        $this->putJson($url, ['clase_rezaga' => 'hoja', 'nombre' => 'Bin de hoja 2'])->assertOk();
        // Omitir la clase tampoco estorba.
        $this->putJson($url, ['nombre' => 'Bin de hoja 3'])->assertOk();

        $this->assertSame('hoja', $this->binHoja->fresh()->clase_rezaga);
        $this->assertSame('Bin de hoja 3', $this->binHoja->fresh()->nombre);
    }

    public function test_se_asigna_la_clase_a_un_bin_nuevo_sin_uso(): void
    {
        $nuevo = $this->crearTipoSinClase('Bin nuevo');

        $this->putJson(self::URL_TIPOS.'/'.$nuevo, ['clase_rezaga' => 'fresco'])
            ->assertOk()
            ->assertJsonPath('data.clase_rezaga', 'fresco');
    }

    public function test_no_se_asigna_clase_a_un_tipo_con_salidas_o_recepciones(): void
    {
        // binCampo ya tiene una recepción.
        $this->crearProcesoBins(5);
        $this->putJson(self::URL_TIPOS.'/'.$this->binCampo->id, ['clase_rezaga' => 'hoja'])
            ->assertStatus(422)
            ->assertJsonPath('errors.clase_rezaga.0', self::MENSAJE_LIGADOS);
        $this->assertNull($this->binCampo->fresh()->clase_rezaga);

        // Otro tipo sin clase con una salida de campo.
        $otro = $this->crearTipoSinClase('Bin con salida');
        $this->postJson(self::URL_SALIDAS, $this->cuerpoSalida(['tipo_carga_id' => $otro]))->assertCreated();

        $this->putJson(self::URL_TIPOS.'/'.$otro, ['clase_rezaga' => 'hoja'])
            ->assertStatus(422)
            ->assertJsonPath('errors.clase_rezaga.0', self::MENSAJE_LIGADOS);
    }

    // (c) topes de la venta por bin

    public function test_precio_bin_enorme_da_422_no_500(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins(10), ['cantidad_bins' => 5, 'cantidad_kg' => 750]);
        $detalles = [['rezaga_id' => $rezaga->id, 'bins' => 2]];

        $this->postJson(self::URL_REZAGA, $this->cuerpoVenta(['precio_bin' => 99999999999, 'detalles' => $detalles]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('precio_bin');
        $this->assertSame(0, SalidaRezagaEmpaque::count());

        // Edición: una venta válida a la que se le sube el precio por encima del tope.
        $id = $this->postJson(self::URL_REZAGA, $this->cuerpoVenta(['detalles' => $detalles]))
            ->assertCreated()->json('data.id');
        $this->putJson(self::URL_REZAGA.'/'.$id, ['precio_bin' => 99999999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('precio_bin');
        $this->assertEquals(100, SalidaRezagaEmpaque::find($id)->precio_bin);

        // El tope exacto sí pasa.
        $this->putJson(self::URL_REZAGA.'/'.$id, ['precio_bin' => 9999999.99])->assertOk();
    }

    public function test_bins_enormes_dan_422(): void
    {
        $rezaga = $this->crearRezagaBins($this->crearProcesoBins(10), ['cantidad_bins' => 5, 'cantidad_kg' => 750]);

        $this->postJson(self::URL_REZAGA, $this->cuerpoVenta([
            'detalles' => [['rezaga_id' => $rezaga->id, 'bins' => 99999999999]],
        ]))->assertStatus(422)->assertJsonValidationErrors('detalles.0.bins');

        $this->assertSame(0, SalidaRezagaEmpaque::count());
    }
}
