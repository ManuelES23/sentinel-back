<?php

namespace Tests\Unit\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioCalibre;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Services\Abastecimiento\PrecioVigente;
use App\Services\Abastecimiento\ReglasRenglonesRecepcion;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Reglas de los renglones de una recepción por calibre. Todo en memoria: el convenio
 * trae sus calibres pactados como relación y los precios salen de un PrecioVigente falso.
 */
class ReglasRenglonesRecepcionTest extends TestCase
{
    private function convenio(string $unidad = 'caja', array $calibres = [5, 6]): Convenio
    {
        $convenio = new Convenio(['unidad' => $unidad, 'usa_calibres' => true]);
        $convenio->id = 1;
        $convenio->setRelation('calibres', collect(array_map(
            fn (int $id) => new ConvenioCalibre(['convenio_id' => 1, 'calibre_id' => $id]),
            $calibres,
        )));

        return $convenio;
    }

    /** Precios por calibre del tipo 1: 5 y 6 desde el 1 de junio; el 6 sube el 1 de julio. */
    private function precios(array $extra = []): PrecioVigente
    {
        $filas = array_merge([
            [5, '2026-06-01', 20], [6, '2026-06-01', 30], [6, '2026-07-01', 35],
        ], $extra);
        $coleccion = collect($filas)->map(function (array $f, int $i) {
            $p = new ConvenioPrecio([
                'convenio_id' => 1, 'tipo_carga_id' => 1, 'calibre_id' => $f[0],
                'precio' => $f[2], 'vigencia_inicio' => $f[1], 'es_principal' => true,
            ]);
            $p->id = $i + 1;

            return $p;
        });

        return new class($coleccion) extends PrecioVigente
        {
            public function __construct(private Collection $todos) {}

            protected function precios(Convenio $convenio): Collection
            {
                return $this->todos;
            }
        };
    }

    private function datos(array $renglones, array $over = []): array
    {
        return array_merge(['fecha' => '2026-06-10', 'tipo_carga_id' => 1, 'renglones' => $renglones], $over);
    }

    /** @return array<string, string> primer mensaje por clave */
    private function errores(Convenio $convenio, array $datos): array
    {
        try {
            ReglasRenglonesRecepcion::validar($convenio, $datos, $this->precios());
        } catch (ValidationException $e) {
            return array_map(fn ($m) => $m[0], $e->errors());
        }

        return [];
    }

    public function test_renglones_validos_pasan(): void
    {
        $this->assertSame([], $this->errores($this->convenio(), $this->datos([
            ['calibre_id' => 5, 'cantidad' => 10],
            ['calibre_id' => 6, 'cantidad' => '4'],
        ])));
        $this->assertSame([], $this->errores($this->convenio(), $this->datos([
            ['calibre_id' => 5, 'cantidad' => 10, 'kilos' => 100.5, 'rezaga_kg' => 0],
            ['calibre_id' => 6, 'cantidad' => 4, 'kilos' => '80.25', 'rezaga_kg' => 1],
        ])));
    }

    public function test_sin_renglones_pide_al_menos_un_calibre(): void
    {
        $e = $this->errores($this->convenio(), $this->datos([]));
        $this->assertSame('Agrega al menos un calibre.', $e['renglones']);
    }

    public function test_calibre_que_no_es_del_convenio(): void
    {
        $e = $this->errores($this->convenio(), $this->datos([['calibre_id' => 99, 'cantidad' => 1]]));
        $this->assertSame('El calibre no es de este convenio.', $e['renglones.0.calibre_id']);
    }

    public function test_calibre_repetido(): void
    {
        $e = $this->errores($this->convenio(), $this->datos([
            ['calibre_id' => 5, 'cantidad' => 1], ['calibre_id' => 5, 'cantidad' => 2],
        ]));
        $this->assertArrayNotHasKey('renglones.0.calibre_id', $e);
        $this->assertSame('Este calibre ya está en la recepción.', $e['renglones.1.calibre_id']);
    }

    public function test_calibre_sin_precio_para_ese_tipo_de_carga(): void
    {
        $convenio = $this->convenio('caja', [5, 6, 7]); // el 7 es pactado pero no tiene precio
        $e = $this->errores($convenio, $this->datos([['calibre_id' => 7, 'cantidad' => 1]]));
        $this->assertSame('Este calibre no tiene precio vigente para ese tipo de carga en esa fecha.', $e['renglones.0.calibre_id']);

        $e = $this->errores($convenio, $this->datos([['calibre_id' => 5, 'cantidad' => 1]], ['tipo_carga_id' => 2]));
        $this->assertSame('Este calibre no tiene precio vigente para ese tipo de carga en esa fecha.', $e['renglones.0.calibre_id']);
    }

    public function test_precio_vigente_por_calibre_a_la_fecha(): void
    {
        // El precio del 6 desde julio no cuenta para una recepción de junio, pero el de junio sí.
        $this->assertSame([], $this->errores($this->convenio(), $this->datos([['calibre_id' => 6, 'cantidad' => 1]])));

        // Un calibre cuyo único precio es posterior a la fecha no tiene precio vigente.
        $conPosterior = $this->convenio('caja', [5, 6, 8]);
        $precios = $this->precios([[8, '2026-07-01', 50]]);
        try {
            ReglasRenglonesRecepcion::validar($conPosterior, $this->datos([['calibre_id' => 8, 'cantidad' => 1]]), $precios);
            $this->fail('Se esperaba un error de precio.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('renglones.0.calibre_id', $e->errors());
        }
        // El mismo calibre sí tiene precio el 1 de julio.
        ReglasRenglonesRecepcion::validar($conPosterior, $this->datos([['calibre_id' => 8, 'cantidad' => 1]], ['fecha' => '2026-07-01']), $precios);
        $this->addToAssertionCount(1);
    }

    public function test_calibre_inactivo_en_el_catalogo_pero_pactado_se_acepta(): void
    {
        // La validez se decide contra los calibres del convenio, sin mirar si el catálogo
        // ya lo dio de baja: el convenio lo pactó y la recepción es legítima.
        $this->assertSame([], $this->errores($this->convenio('caja', [5]), $this->datos([['calibre_id' => 5, 'cantidad' => 3]])));
    }

    public function test_cantidad_entera_mayor_a_cero(): void
    {
        foreach ([0, -2, 1.5, 'abc', null] as $malo) {
            $e = $this->errores($this->convenio(), $this->datos([['calibre_id' => 5, 'cantidad' => $malo]]));
            $this->assertSame('La cantidad debe ser un número entero mayor a cero.', $e['renglones.0.cantidad'], var_export($malo, true));
        }
    }

    public function test_convenio_por_kilo_exige_kilos(): void
    {
        $e = $this->errores($this->convenio('kg'), $this->datos([['calibre_id' => 5, 'cantidad' => 2]]));
        $this->assertSame('Indica los kilos de báscula.', $e['renglones.0.kilos']);
    }

    public function test_rezaga_exige_kilos_en_ese_renglon(): void
    {
        $e = $this->errores($this->convenio(), $this->datos([['calibre_id' => 5, 'cantidad' => 2, 'rezaga_kg' => 1]]));
        $this->assertSame('Indica los kilos de báscula.', $e['renglones.0.kilos']);
    }

    public function test_kilos_a_medias_da_422(): void
    {
        $e = $this->errores($this->convenio(), $this->datos([
            ['calibre_id' => 5, 'cantidad' => 2, 'kilos' => 40],
            ['calibre_id' => 6, 'cantidad' => 2],
        ]));
        $this->assertArrayNotHasKey('renglones.0.kilos', $e);
        $this->assertSame('Captura los kilos de todos los calibres o de ninguno.', $e['renglones.1.kilos']);
    }

    public function test_decimales_y_signos_de_kilos_y_rezaga(): void
    {
        $e = $this->errores($this->convenio(), $this->datos([
            ['calibre_id' => 5, 'cantidad' => 1, 'kilos' => 10.123, 'rezaga_kg' => 0.005],
            ['calibre_id' => 6, 'cantidad' => 1, 'kilos' => 10, 'rezaga_kg' => -1],
        ]));
        $this->assertSame('Los kilos aceptan hasta 2 decimales.', $e['renglones.0.kilos']);
        $this->assertSame('La rezaga acepta hasta 2 decimales.', $e['renglones.0.rezaga_kg']);
        $this->assertSame('La rezaga no puede ser negativa.', $e['renglones.1.rezaga_kg']);
    }

    public function test_rezaga_no_supera_los_kilos(): void
    {
        $e = $this->errores($this->convenio(), $this->datos([['calibre_id' => 5, 'cantidad' => 1, 'kilos' => 10, 'rezaga_kg' => 10.5]]));
        $this->assertSame('La rezaga no puede superar los kilos.', $e['renglones.0.rezaga_kg']);
    }

    public function test_junta_todos_los_errores(): void
    {
        $e = $this->errores($this->convenio('kg'), $this->datos([
            ['calibre_id' => 99, 'cantidad' => 0],
            ['calibre_id' => 5, 'cantidad' => 1],
        ]));
        $this->assertEqualsCanonicalizing(
            ['renglones.0.calibre_id', 'renglones.0.cantidad', 'renglones.0.kilos', 'renglones.1.kilos'],
            array_keys($e),
        );
    }

    public function test_totales_de_cabecera_con_y_sin_kilos(): void
    {
        $con = ReglasRenglonesRecepcion::totalesCabecera([
            ['calibre_id' => 5, 'cantidad' => 10, 'kilos' => 100.5, 'rezaga_kg' => 2],
            ['calibre_id' => 6, 'cantidad' => '5', 'kilos' => '50.25', 'rezaga_kg' => null],
        ]);
        $this->assertSame(['cantidad' => 15.0, 'kilos' => 150.75, 'rezaga_kg' => 2.0], $con);

        $sin = ReglasRenglonesRecepcion::totalesCabecera([
            ['calibre_id' => 5, 'cantidad' => 10], ['calibre_id' => 6, 'cantidad' => 4, 'kilos' => '', 'rezaga_kg' => ''],
        ]);
        $this->assertSame(['cantidad' => 14.0, 'kilos' => null, 'rezaga_kg' => null], $sin);
    }
}
