<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\AssetCategory;
use App\Models\Brand;
use App\Models\Entity;
use App\Models\FixedAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\CreatesAssetFixtures;
use Tests\TestCase;

class ImportLegacyEmpresaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAssetFixtures;

    private ?string $xlsx = null;

    protected function tearDown(): void
    {
        if ($this->xlsx && is_file($this->xlsx)) {
            unlink($this->xlsx);
        }
        parent::tearDown();
    }

    public function test_exige_la_empresa(): void
    {
        $this->artisan('assets:import-legacy', ['path' => 'no-importa.xlsx'])
            ->expectsOutputToContain('--empresa')
            ->assertExitCode(1);
    }

    public function test_rechaza_una_empresa_inexistente(): void
    {
        $this->artisan('assets:import-legacy', ['path' => 'no-importa.xlsx', '--empresa' => 'nada'])
            ->expectsOutputToContain('no existe')
            ->assertExitCode(1);
    }

    public function test_importa_dentro_de_la_empresa_indicada(): void
    {
        $this->setUpAssetFixtures();

        // Misma entidad "Oficina Mochis" en otra empresa, creada ANTES: una búsqueda
        // sin acotar por empresa la encontraría primero.
        $sp = $this->crearEmpresaActivos('splendidbyporvenir', 'Splendid by Porvenir', 'SP');
        [, $entidadSp] = $this->crearUbicacion($sp, 'SP');
        $entidadSp->update(['name' => 'Oficina Mochis']);
        $this->entity->update(['name' => 'Oficina Mochis']);

        // "HP" existe solo en SP: para SF debe crearse una marca propia.
        $hpSp = Brand::create(['code' => 'MRC-050', 'name' => 'HP', 'is_active' => true]);
        $hpSp->enterprises()->attach($sp->id);

        $this->xlsx = $this->crearExcel([
            // Sin código: lo asigna el generador con el prefijo de SF.
            ['', 'Laptop sin código', 'SN-1', 'Los Mochis', 'Equipos de cómputo', 'Laptops', 'Dell', 'Disponible', 'hace 2 años', ''],
            // Con código legacy: se conserva, marca nueva para SF y tipo nuevo.
            ['LEG-001', 'Impresora legacy', 'SN-2', 'Los Mochis', 'Impresión', '', 'HP', 'En uso', '', ''],
        ]);

        $this->artisan('assets:import-legacy', ['path' => $this->xlsx, '--empresa' => 'splendidfarms'])
            ->assertExitCode(0);

        $sinCodigo = FixedAsset::where('name', 'Laptop sin código')->firstOrFail();
        $this->assertSame($this->enterprise->id, $sinCodigo->enterprise_id);
        $this->assertSame($this->entity->id, $sinCodigo->entity_id);
        $this->assertSame($this->branch->id, $sinCodigo->branch_id);
        $this->assertSame('SF-AF-000001', $sinCodigo->code);
        $this->assertSame($this->brand->id, $sinCodigo->brand_id);

        $legacy = FixedAsset::where('code', 'LEG-001')->firstOrFail();
        $this->assertSame($this->enterprise->id, $legacy->enterprise_id);
        $this->assertSame($this->entity->id, $legacy->entity_id);
        $this->assertNotSame($entidadSp->id, $legacy->entity_id);

        $hpSf = Brand::find($legacy->brand_id);
        $this->assertSame('HP', $hpSf->name);
        $this->assertNotSame($hpSp->id, $hpSf->id);
        $this->assertTrue($hpSf->enterprises()->where('enterprises.id', $this->enterprise->id)->exists());

        $this->assertSame('TAC-003', AssetCategory::find($legacy->category_id)->code);
        $this->assertSame(0, FixedAsset::where('enterprise_id', $sp->id)->count());
        $this->assertSame(1, Entity::where('name', 'Oficina Mochis')->where('branch_id', $this->branch->id)->count());
    }

    private function crearExcel(array $filas): string
    {
        $hoja = (new Spreadsheet())->getActiveSheet();
        $hoja->fromArray(['Activos Fijos legacy'], null, 'A1');
        $hoja->fromArray(['Código', 'Imagen', 'Nombre', 'Num. Serie', 'Entidad', 'Tipo Activo', 'Subtipo Activo', 'Marca', 'Estado', 'Fecha Compra', 'Observaciones'], null, 'A2');

        foreach ($filas as $i => $f) {
            // Las filas del test omiten la columna B (Imagen).
            $hoja->fromArray([$f[0], '', $f[1], $f[2], $f[3], $f[4], $f[5], $f[6], $f[7], $f[8], $f[9]], null, 'A'.($i + 3));
        }

        $ruta = tempnam(sys_get_temp_dir(), 'legacy').'.xlsx';
        (new Xlsx($hoja->getParent()))->save($ruta);

        return $ruta;
    }
}
