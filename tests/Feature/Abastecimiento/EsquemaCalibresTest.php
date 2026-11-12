<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioCalibre;
use App\Models\Abastecimiento\ConvenioMeta;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Variedad;
use App\Models\Abastecimiento\VariedadCalibre;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

class EsquemaCalibresTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    private function calibre(Variedad $variedad, string $nombre, int $orden = 0): VariedadCalibre
    {
        return VariedadCalibre::create(['variedad_id' => $variedad->id, 'nombre' => $nombre, 'orden' => $orden]);
    }

    public function test_tablas_y_columnas_de_calibres(): void
    {
        $esperado = [
            'abs_variedad_calibres' => ['id', 'variedad_id', 'nombre', 'orden', 'is_active', 'created_at', 'updated_at', 'deleted_at'],
            'abs_convenios' => ['usa_calibres'],
            'abs_convenio_calibres' => ['id', 'convenio_id', 'calibre_id', 'volumen_total', 'created_at', 'updated_at'],
            'abs_convenio_precios' => ['calibre_id'],
            'abs_convenio_metas' => ['calibre_id'],
            'abs_liquidacion_detalles' => ['calibre_id'],
        ];

        foreach ($esperado as $tabla => $columnas) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}");
            $this->assertTrue(Schema::hasColumns($tabla, $columnas), "Faltan columnas en {$tabla}");
        }
    }

    public function test_modelos_y_relaciones_de_calibres(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $variedad = $convenio->variedad;
        $grande = $this->calibre($variedad, 'Grande', 2);
        $chico = $this->calibre($variedad, 'Chico', 1);

        $this->assertFalse($convenio->fresh()->usa_calibres);
        $this->assertSame([$chico->id, $grande->id], $variedad->calibres()->orderBy('orden')->pluck('id')->all());
        $this->assertSame($variedad->id, $grande->variedad->id);
        $this->assertTrue(VariedadCalibre::deEmpresa($this->sbp->id)->whereKey($grande->id)->exists());
        $this->assertFalse(VariedadCalibre::deEmpresa($this->otra->id)->whereKey($grande->id)->exists());

        $cc = ConvenioCalibre::create(['convenio_id' => $convenio->id, 'calibre_id' => $grande->id, 'volumen_total' => 500]);
        $this->assertSame($convenio->id, $cc->convenio->id);
        $this->assertSame($grande->id, $cc->calibre->id);
        $this->assertCount(1, $convenio->calibres);

        $precio = ConvenioPrecio::create([
            'convenio_id' => $convenio->id,
            'tipo_carga_id' => $convenio->precios->first()->tipo_carga_id,
            'precio' => 90, 'vigencia_inicio' => '2026-06-08', 'calibre_id' => $grande->id,
        ]);
        $this->assertSame($grande->id, $precio->calibre->id);
        $meta = ConvenioMeta::create(['convenio_id' => $convenio->id, 'semana' => '2026-06-29', 'cantidad' => 5, 'calibre_id' => $grande->id]);
        $this->assertSame($grande->id, $meta->calibre->id);
    }

    public function test_dos_precios_clasicos_iguales_chocan(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $base = $convenio->precios->first();

        $this->expectException(QueryException::class);
        ConvenioPrecio::create([
            'convenio_id' => $convenio->id,
            'tipo_carga_id' => $base->tipo_carga_id,
            'precio' => 120,
            'vigencia_inicio' => $base->vigencia_inicio->format('Y-m-d'),
            'calibre_id' => null,
        ]);
    }

    public function test_mismo_tipo_y_vigencia_con_calibres_distintos_conviven(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $base = $convenio->precios->first();
        $a = $this->calibre($convenio->variedad, 'A');
        $b = $this->calibre($convenio->variedad, 'B');

        foreach ([$a, $b] as $calibre) {
            ConvenioPrecio::create([
                'convenio_id' => $convenio->id,
                'tipo_carga_id' => $base->tipo_carga_id,
                'precio' => 120,
                'vigencia_inicio' => $base->vigencia_inicio->format('Y-m-d'),
                'calibre_id' => $calibre->id,
            ]);
        }

        $this->assertSame(3, ConvenioPrecio::where('convenio_id', $convenio->id)->count());

        // Y el mismo calibre dos veces con el mismo tipo y vigencia sí choca.
        $this->expectException(QueryException::class);
        ConvenioPrecio::create([
            'convenio_id' => $convenio->id,
            'tipo_carga_id' => $base->tipo_carga_id,
            'precio' => 130,
            'vigencia_inicio' => $base->vigencia_inicio->format('Y-m-d'),
            'calibre_id' => $a->id,
        ]);
    }

    public function test_dos_metas_clasicas_de_la_misma_semana_chocan(): void
    {
        $convenio = $this->crearConvenio($this->sbp);

        $this->expectException(QueryException::class);
        ConvenioMeta::create(['convenio_id' => $convenio->id, 'semana' => '2026-06-01', 'cantidad' => 7, 'calibre_id' => null]);
    }

    public function test_misma_semana_con_calibres_distintos_conviven(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $a = $this->calibre($convenio->variedad, 'A');
        $b = $this->calibre($convenio->variedad, 'B');

        foreach ([$a, $b] as $calibre) {
            ConvenioMeta::create(['convenio_id' => $convenio->id, 'semana' => '2026-06-01', 'cantidad' => 7, 'calibre_id' => $calibre->id]);
        }

        $this->assertSame(3, ConvenioMeta::where('convenio_id', $convenio->id)->where('semana', '2026-06-01')->count());
    }

    public function test_calibre_unico_por_variedad_incluso_borrado(): void
    {
        $convenio = $this->crearConvenio($this->sbp);
        $variedad = $convenio->variedad;
        $calibre = $this->calibre($variedad, 'Jumbo');
        $calibre->delete();

        $this->expectException(QueryException::class);
        $this->calibre($variedad, 'Jumbo');
    }
}
