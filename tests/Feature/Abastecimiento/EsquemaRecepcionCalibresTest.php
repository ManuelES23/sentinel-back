<?php

namespace Tests\Feature\Abastecimiento;

use App\Models\Abastecimiento\Cultivo;
use App\Models\Abastecimiento\RecepcionCalibre;
use App\Models\Abastecimiento\Variedad;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAbastecimientoFixtures;
use Tests\TestCase;

/** Renglones de recepción por calibre: tabla, único por calibre, cascada y relaciones. */
class EsquemaRecepcionCalibresTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAbastecimientoFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAbastecimiento();
    }

    private function variedadMango(): Variedad
    {
        $cultivo = Cultivo::where('enterprise_id', $this->sbp->id)->where('nombre', 'Mango')->firstOrFail();

        return Variedad::where('cultivo_id', $cultivo->id)->orderBy('id')->firstOrFail();
    }

    public function test_tabla_y_columnas(): void
    {
        $this->assertTrue(Schema::hasTable('abs_recepcion_calibres'));
        $this->assertTrue(Schema::hasColumns('abs_recepcion_calibres', [
            'id', 'recepcion_id', 'calibre_id', 'cantidad', 'kilos', 'rezaga_kg', 'created_at', 'updated_at',
        ]));
        $this->assertFalse(Schema::hasColumn('abs_recepcion_calibres', 'deleted_at'));
    }

    public function test_un_calibre_por_recepcion(): void
    {
        $jumbo = $this->crearCalibre($this->variedadMango(), 'Jumbo');
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [['calibre' => $jumbo, 'volumen' => 100, 'precio' => 120.0]]);
        $recepcion = $this->crearRecepcionConRenglones($convenio, [['calibre_id' => $jumbo->id, 'cantidad' => 10]]);

        $this->expectException(QueryException::class);
        RecepcionCalibre::create(['recepcion_id' => $recepcion->id, 'calibre_id' => $jumbo->id, 'cantidad' => 5]);
    }

    public function test_borrar_la_recepcion_de_verdad_borra_los_renglones(): void
    {
        $variedad = $this->variedadMango();
        $jumbo = $this->crearCalibre($variedad, 'Jumbo');
        $grande = $this->crearCalibre($variedad, 'Grande');
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $jumbo, 'volumen' => 100, 'precio' => 120.0],
            ['calibre' => $grande, 'volumen' => 50, 'precio' => 90.0],
        ]);
        $recepcion = $this->crearRecepcionConRenglones($convenio, [
            ['calibre_id' => $jumbo->id, 'cantidad' => 10],
            ['calibre_id' => $grande->id, 'cantidad' => 4],
        ]);
        $this->assertSame(2, RecepcionCalibre::count());

        $recepcion->delete(); // borrado lógico: los renglones se quedan
        $this->assertSame(2, RecepcionCalibre::count());

        $recepcion->forceDelete();
        $this->assertSame(0, RecepcionCalibre::count());
    }

    public function test_relacion_renglones_y_calibre_con_borrados(): void
    {
        $variedad = $this->variedadMango();
        $jumbo = $this->crearCalibre($variedad, 'Jumbo');
        $grande = $this->crearCalibre($variedad, 'Grande');
        $convenio = $this->crearConvenioPorCalibre($this->sbp, [
            ['calibre' => $jumbo, 'volumen' => 100, 'precio' => 120.0],
            ['calibre' => $grande, 'volumen' => 50, 'precio' => 90.0],
        ]);
        $recepcion = $this->crearRecepcionConRenglones($convenio, [
            ['calibre_id' => $grande->id, 'cantidad' => 4, 'kilos' => 20.5, 'rezaga_kg' => 1],
            ['calibre_id' => $jumbo->id, 'cantidad' => 10],
        ]);

        // La cabecera es la suma de los renglones.
        $recepcion->refresh();
        $this->assertSame('14.00', $recepcion->cantidad);
        $this->assertSame('20.50', $recepcion->kilos);
        $this->assertSame('1.00', $recepcion->rezaga_kg);

        $renglones = $recepcion->renglones;
        $this->assertSame([$grande->id, $jumbo->id], $renglones->pluck('calibre_id')->all()); // orden por id
        $this->assertSame($recepcion->id, $renglones[0]->recepcion->id);
        $this->assertSame('Grande', $renglones[0]->calibre->nombre);
        $this->assertSame('4.00', $renglones[0]->cantidad);
        $this->assertNull($renglones[1]->kilos);

        // Un calibre borrado sigue mostrando su nombre.
        $grande->delete();
        $this->assertSame('Grande', $recepcion->renglones()->first()->calibre->nombre);
    }
}
