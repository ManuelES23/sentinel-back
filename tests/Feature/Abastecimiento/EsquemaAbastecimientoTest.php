<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\Productor;
use App\Models\Abastecimiento\Temporada;
use App\Models\Abastecimiento\TipoCarga;
use App\Models\Abastecimiento\Variedad;
use App\Models\Enterprise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EsquemaAbastecimientoTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(string $slug = 'splendidbyporvenir'): Enterprise
    {
        return Enterprise::create([
            'name' => $slug, 'slug' => $slug, 'description' => $slug, 'is_active' => true,
        ]);
    }

    public function test_crea_las_tablas_con_sus_columnas(): void
    {
        $esperado = [
            'abs_cultivos' => ['id', 'enterprise_id', 'nombre', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
            'abs_variedades' => ['id', 'cultivo_id', 'nombre', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
            'abs_tipos_carga' => ['id', 'enterprise_id', 'nombre', 'peso_kg', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
            'abs_productores' => ['id', 'enterprise_id', 'tipo', 'nombre', 'rfc', 'contacto', 'telefono', 'email', 'ubicacion', 'notas', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
            'abs_productor_cultivo' => ['id', 'productor_id', 'cultivo_id', 'created_at', 'updated_at'],
            'abs_temporadas' => ['id', 'enterprise_id', 'cultivo_id', 'nombre', 'fecha_inicio', 'fecha_fin', 'estado', 'created_at', 'updated_at', 'deleted_at'],
            'abs_convenios' => ['id', 'enterprise_id', 'folio', 'temporada_id', 'productor_id', 'cultivo_id', 'variedad_id', 'modalidad', 'unidad', 'moneda', 'porcentaje_rezaga', 'fecha_inicio', 'fecha_fin', 'modo_volumen', 'volumen_total', 'status', 'notas', 'created_by', 'created_at', 'updated_at', 'deleted_at'],
            'abs_convenio_precios' => ['id', 'convenio_id', 'tipo_carga_id', 'precio', 'porcentaje_productor', 'vigencia_inicio', 'es_principal', 'notas', 'created_at', 'updated_at', 'deleted_at'],
            'abs_convenio_metas' => ['id', 'convenio_id', 'semana', 'cantidad', 'created_at', 'updated_at'],
            'abs_recepciones' => ['id', 'enterprise_id', 'folio', 'convenio_id', 'fecha', 'tipo_carga_id', 'cantidad', 'kilos', 'rezaga_kg', 'ticket_proveedor', 'notas', 'liquidacion_id', 'created_by', 'created_at', 'updated_at', 'deleted_at'],
            'abs_folios' => ['id', 'enterprise_id', 'prefijo', 'ultimo', 'created_at', 'updated_at'],
        ];

        foreach ($esperado as $tabla => $columnas) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}");
            $this->assertTrue(Schema::hasColumns($tabla, $columnas), "Faltan columnas en {$tabla}");
        }
    }

    public function test_convenio_carga_sus_relaciones(): void
    {
        $empresa = $this->empresa();

        $cultivo = Cultivo::create(['enterprise_id' => $empresa->id, 'nombre' => 'Arándano']);
        $variedad = Variedad::create(['cultivo_id' => $cultivo->id, 'nombre' => 'Biloxi']);
        $productor = Productor::create(['enterprise_id' => $empresa->id, 'tipo' => 'productor', 'nombre' => 'Rancho Uno']);
        $productor->cultivos()->attach($cultivo->id);
        $temporada = Temporada::create([
            'enterprise_id' => $empresa->id, 'cultivo_id' => $cultivo->id, 'nombre' => 'Arándano 26-27',
            'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2027-05-31', 'estado' => 'activa',
        ]);
        $tipo = TipoCarga::create(['enterprise_id' => $empresa->id, 'nombre' => 'Caja 6 oz', 'peso_kg' => 1.2]);

        $convenio = Convenio::create([
            'enterprise_id' => $empresa->id, 'folio' => 'CV-0001', 'temporada_id' => $temporada->id,
            'productor_id' => $productor->id, 'cultivo_id' => $cultivo->id, 'variedad_id' => $variedad->id,
            'modalidad' => 'compra_directa', 'unidad' => 'caja', 'moneda' => 'MXN',
            'fecha_inicio' => '2026-11-01', 'fecha_fin' => '2027-05-31', 'modo_volumen' => 'semanal',
        ]);
        $convenio->precios()->create([
            'tipo_carga_id' => $tipo->id, 'precio' => 120.5, 'vigencia_inicio' => '2026-11-01', 'es_principal' => true,
        ]);
        $convenio->metas()->create(['semana' => '2026-11-02', 'cantidad' => 500]);
        $recepcion = $convenio->recepciones()->create([
            'enterprise_id' => $empresa->id, 'folio' => 'RC-0001', 'fecha' => '2026-11-03',
            'tipo_carga_id' => $tipo->id, 'cantidad' => 100,
        ]);

        $convenio->load('precios', 'metas', 'recepciones', 'temporada', 'productor', 'cultivo', 'variedad');

        $this->assertCount(1, $convenio->precios);
        $this->assertCount(1, $convenio->metas);
        $this->assertCount(1, $convenio->recepciones);
        $this->assertSame($temporada->id, $convenio->temporada->id);
        $this->assertSame($productor->id, $convenio->productor->id);
        $this->assertSame($cultivo->id, $convenio->cultivo->id);
        $this->assertSame($variedad->id, $convenio->variedad->id);
        $this->assertSame($cultivo->id, $variedad->cultivo->id);
        $this->assertCount(1, $productor->cultivos);
        $this->assertSame($convenio->id, $recepcion->convenio->id);
        $this->assertSame($tipo->id, $recepcion->tipoCarga->id);
        $this->assertFalse($recepcion->estaLiquidada());
        $this->assertSame('2026-11-03', $recepcion->fecha->format('Y-m-d'));
        $this->assertSame('borrador', $convenio->fresh()->status);
    }

    public function test_scope_de_empresa_separa_empresas(): void
    {
        $a = $this->empresa('empresa-a');
        $b = $this->empresa('empresa-b');

        Productor::create(['enterprise_id' => $a->id, 'tipo' => 'productor', 'nombre' => 'De A']);
        Productor::create(['enterprise_id' => $b->id, 'tipo' => 'empaque', 'nombre' => 'De B']);

        $this->assertSame(1, Productor::deEmpresa($a->id)->count());
        $this->assertSame('De A', Productor::deEmpresa($a->id)->first()->nombre);
    }
}
