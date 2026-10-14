<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\Branch;
use App\Models\Enterprise;
use App\Models\Entity;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\User;
use App\Services\ActivosFijos\RegistradorSalidaActivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\Concerns\CreatesDepreciacionFixtures;
use Tests\Concerns\CreatesSalidaFixtures;
use Tests\TestCase;

class TraspasoSalidaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;
    use CreatesDepreciacionFixtures;
    use CreatesSalidaFixtures;

    private RegistradorSalidaActivo $registrador;
    private User $usuario;
    private Branch $sucursalGe;
    private Entity $entidadGe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAssetFixtures();
        $this->travelTo('2026-06-15 10:00:00');
        $this->registrador = app(RegistradorSalidaActivo::class);
        $this->usuario = User::factory()->create(['name' => 'Marta Gómez', 'role' => 'user']);
        [$this->sucursalGe, $this->entidadGe] = $this->crearUbicacion($this->corporativo, 'GE');
    }

    private function datosTraspaso(array $overrides = []): array
    {
        return array_merge([
            'type' => 'traspaso',
            'disposal_date' => '2026-05-20',
            'reason' => 'Se reasigna el equipo al corporativo',
            'authorized_by_name' => 'Dirección General',
            'destination_enterprise_id' => $this->corporativo->id,
            'destination_branch_id' => $this->sucursalGe->id,
            'destination_entity_id' => $this->entidadGe->id,
        ], $overrides);
    }

    private function assertTraspasoRechazado(FixedAsset $origen, array $datos, string $clave, ?array $permitidos = null): void
    {
        try {
            $this->registrador->registrar($origen, $datos, $this->usuario, $permitidos ?? [$this->corporativo->id]);
            $this->fail('El traspaso debió rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($clave, $e->errors());
        }

        $this->assertSame(0, FixedAssetDisposal::count());
        $this->assertSame(1, FixedAsset::count());
        $this->assertSame('en_uso', $origen->fresh()->status);
    }

    public function test_el_traspaso_crea_el_activo_en_la_receptora_con_los_datos_acordados(): void
    {
        $origen = $this->crearActivoDepreciable(['serial_number' => 'SN-123', 'model' => 'Latitude 5530', 'year' => 2024]);

        $salida = $this->registrador->registrar($origen, $this->datosTraspaso(), $this->usuario, [$this->corporativo->id]);

        $destino = FixedAsset::findOrFail($salida->destination_asset_id);
        $this->assertSame('traspaso', $salida->type);
        $this->assertSame('0.00', $salida->result_amount);
        $this->assertSame('11100.00', $salida->book_value);
        $this->assertSame($this->corporativo->id, $salida->destination_enterprise_id);
        $this->assertSame('baja', $origen->fresh()->status);

        $this->assertSame($this->corporativo->id, $destino->enterprise_id);
        $this->assertStringStartsWith('GE-AF-', $destino->code);
        $this->assertSame($origen->name, $destino->name);
        $this->assertSame($origen->category_id, $destino->category_id);
        $this->assertSame($origen->subcategory_id, $destino->subcategory_id);
        $this->assertSame('SN-123', $destino->serial_number);
        $this->assertSame('Latitude 5530', $destino->model);
        $this->assertSame(2024, $destino->year);
        $this->assertSame('11100.00', $destino->purchase_value);
        $this->assertSame('2026-05-20', $destino->purchase_date->toDateString());
        $this->assertSame('2026-06-01', $destino->depreciation_start->toDateString());
        $this->assertSame('20.00', $destino->depreciation_rate);
        $this->assertSame('10.00', $destino->salvage_percent);
        $this->assertSame('disponible', $destino->status);
        $this->assertSame($this->sucursalGe->id, $destino->branch_id);
        $this->assertSame($this->entidadGe->id, $destino->entity_id);
        $this->assertNull($destino->area_id);
        $this->assertStringContainsString($origen->code, $destino->observations);
    }

    public function test_la_marca_solo_se_copia_si_existe_en_la_receptora(): void
    {
        $origen = $this->crearActivoDepreciable(); // la marca de los fixtures solo está dada de alta en Splendid Farms
        $sinMarca = $this->registrador->registrar($origen, $this->datosTraspaso(), $this->usuario, [$this->corporativo->id]);
        $this->assertNull(FixedAsset::findOrFail($sinMarca->destination_asset_id)->brand_id);

        $this->brand->enterprises()->attach($this->corporativo->id);
        $otro = $this->crearActivoDepreciable();
        $conMarca = $this->registrador->registrar($otro, $this->datosTraspaso(), $this->usuario, [$this->corporativo->id]);
        $this->assertSame($this->brand->id, FixedAsset::findOrFail($conMarca->destination_asset_id)->brand_id);
    }

    public function test_la_empresa_destino_debe_estar_entre_las_permitidas(): void
    {
        $origen = $this->crearActivoDepreciable();

        $this->assertTraspasoRechazado($origen, $this->datosTraspaso(), 'destination_enterprise_id', []);
        $this->assertTraspasoRechazado($origen, $this->datosTraspaso(['destination_enterprise_id' => 999999]), 'destination_enterprise_id');
    }

    public function test_el_traspaso_a_la_misma_empresa_se_rechaza(): void
    {
        $origen = $this->crearActivoDepreciable();

        $this->assertTraspasoRechazado(
            $origen,
            $this->datosTraspaso(['destination_enterprise_id' => $this->enterprise->id]),
            'destination_enterprise_id',
            [$this->enterprise->id],
        );
    }

    public function test_la_sucursal_y_la_entidad_deben_ser_de_la_receptora(): void
    {
        $origen = $this->crearActivoDepreciable();

        $this->assertTraspasoRechazado($origen, $this->datosTraspaso(['destination_branch_id' => $this->branch->id]), 'destination_branch_id');
        $this->assertTraspasoRechazado($origen, $this->datosTraspaso(['destination_entity_id' => $this->entity->id]), 'destination_entity_id');
    }

    public function test_la_sucursal_y_la_entidad_son_obligatorias(): void
    {
        $origen = $this->crearActivoDepreciable();
        $sinEntidad = $this->datosTraspaso();
        unset($sinEntidad['destination_entity_id']);

        $this->assertTraspasoRechazado($origen, $sinEntidad, 'destination_entity_id');
    }

    public function test_si_falla_el_alta_en_la_receptora_no_queda_nada(): void
    {
        $sinPrefijo = Enterprise::create(['name' => 'Sin prefijo', 'slug' => 'sinprefijo', 'description' => 'x', 'is_active' => true]);
        [$sucursal, $entidad] = $this->crearUbicacion($sinPrefijo, 'SP');
        $origen = $this->crearActivoDepreciable();

        $this->assertTraspasoRechazado($origen, $this->datosTraspaso([
            'destination_enterprise_id' => $sinPrefijo->id,
            'destination_branch_id' => $sucursal->id,
            'destination_entity_id' => $entidad->id,
        ]), 'code', [$sinPrefijo->id]);
    }
}
