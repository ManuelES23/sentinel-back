<?php

namespace Tests\Unit\Abastecimiento;

use App\Services\Abastecimiento\ReglasConvenio;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Reglas puras de precios, calibres y volumen por calibre: no tocan la base. */
class ReglasConvenioCalibresTest extends TestCase
{
    private function precio(array $extra = []): array
    {
        return array_merge([
            'tipo_carga_id' => 1,
            'precio' => 10,
            'vigencia_inicio' => '2026-06-01',
            'es_principal' => true,
        ], $extra);
    }

    /** @return array<string, string> */
    private function errores(callable $fn): array
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return array_map(fn ($m) => $m[0], $e->errors());
        }
        $this->fail('Se esperaba una ValidationException.');
    }

    // ---- validarPrecios ----

    public function test_precios_clasicos_siguen_igual(): void
    {
        ReglasConvenio::validarPrecios([$this->precio()], 'compra');
        ReglasConvenio::validarPrecios([$this->precio()], 'compra', false);

        $e = $this->errores(fn () => ReglasConvenio::validarPrecios([
            $this->precio(), $this->precio(['precio' => 12]),
        ], 'compra'));
        $this->assertSame('Ya hay un precio de este tipo de carga con esa fecha de vigencia.', $e['precios.1.vigencia_inicio']);

        $e = $this->errores(fn () => ReglasConvenio::validarPrecios([
            $this->precio(), $this->precio(['tipo_carga_id' => 2, 'vigencia_inicio' => '2026-07-01', 'es_principal' => true]),
        ], 'compra'));
        $this->assertArrayHasKey('precios.1.es_principal', $e);
    }

    public function test_misma_vigencia_en_calibres_distintos_es_valida(): void
    {
        ReglasConvenio::validarPrecios([
            $this->precio(['calibre_id' => 10]),
            $this->precio(['calibre_id' => 11]),
        ], 'compra', true);

        $e = $this->errores(fn () => ReglasConvenio::validarPrecios([
            $this->precio(['calibre_id' => 10]),
            $this->precio(['calibre_id' => 10, 'precio' => 11]),
        ], 'compra', true));
        $this->assertArrayHasKey('precios.1.vigencia_inicio', $e);
    }

    public function test_con_calibres_cada_precio_exige_calibre(): void
    {
        $e = $this->errores(fn () => ReglasConvenio::validarPrecios([
            $this->precio(['calibre_id' => 10]),
            $this->precio(['vigencia_inicio' => '2026-07-01']),
        ], 'compra', true));
        $this->assertSame(['precios.1.calibre_id' => 'Elige el calibre.'], $e);
    }

    public function test_sin_calibres_un_calibre_se_rechaza(): void
    {
        $e = $this->errores(fn () => ReglasConvenio::validarPrecios([
            $this->precio(['calibre_id' => 10]),
        ], 'compra', false));
        $this->assertSame(['precios.0.calibre_id' => 'Este convenio no es por calibre.'], $e);
    }

    public function test_normalizar_precios_agrega_calibre(): void
    {
        $filas = ReglasConvenio::normalizarPrecios([
            $this->precio(['calibre_id' => '10']),
            $this->precio(['calibre_id' => null, 'vigencia_inicio' => '2026-07-01']),
            $this->precio(['vigencia_inicio' => '2026-08-01']),
        ], 'compra');

        $this->assertSame(10, $filas[0]['calibre_id']);
        $this->assertNull($filas[1]['calibre_id']);
        $this->assertNull($filas[2]['calibre_id']);
        $this->assertTrue($filas[0]['es_principal']);
    }

    // ---- validarCalibres ----

    private function preciosNormalizados(array $calibresPorTipo): array
    {
        $filas = [];
        foreach ($calibresPorTipo as $tipo => $calibres) {
            foreach ($calibres as $cal) {
                $filas[] = $this->precio(['tipo_carga_id' => $tipo, 'calibre_id' => $cal, 'es_principal' => $tipo === 1]);
            }
        }

        return ReglasConvenio::normalizarPrecios($filas, 'compra');
    }

    public function test_calibres_validos_pasan(): void
    {
        ReglasConvenio::validarCalibres(
            [['calibre_id' => 10, 'volumen_total' => null], ['calibre_id' => 11, 'volumen_total' => null]],
            [10, 11, 12],
            $this->preciosNormalizados([1 => [10, 11], 2 => [10]]),
        );
        $this->assertTrue(true);
    }

    public function test_sin_calibres_se_rechaza(): void
    {
        $e = $this->errores(fn () => ReglasConvenio::validarCalibres([], [10], []));
        $this->assertSame(['calibres' => 'Elige al menos un calibre.'], $e);
    }

    public function test_calibre_no_permitido(): void
    {
        $e = $this->errores(fn () => ReglasConvenio::validarCalibres(
            [['calibre_id' => 10, 'volumen_total' => null], ['calibre_id' => 99, 'volumen_total' => null]],
            [10, 11],
            $this->preciosNormalizados([1 => [10, 99]]),
        ));
        $this->assertSame('El calibre no es de la variedad del convenio o está inactivo.', $e['calibres.1.calibre_id']);
        $this->assertArrayNotHasKey('calibres.0.calibre_id', $e);
    }

    public function test_precio_de_calibre_no_elegido(): void
    {
        $e = $this->errores(fn () => ReglasConvenio::validarCalibres(
            [['calibre_id' => 10, 'volumen_total' => null]],
            [10, 11],
            $this->preciosNormalizados([1 => [10, 11]]),
        ));
        $this->assertSame(['precios.1.calibre_id' => 'El calibre no está en el convenio.'], $e);
    }

    public function test_calibre_sin_precio_del_principal(): void
    {
        // El calibre 11 solo tiene precio del tipo 2 (no principal).
        $e = $this->errores(fn () => ReglasConvenio::validarCalibres(
            [['calibre_id' => 10, 'volumen_total' => null], ['calibre_id' => 11, 'volumen_total' => null]],
            [10, 11],
            $this->preciosNormalizados([1 => [10], 2 => [10, 11]]),
        ));
        $this->assertSame(['calibres.1.calibre_id' => 'Falta el precio del tipo de carga principal para este calibre.'], $e);
    }

    // ---- validarVolumen ----

    private function datos(array $extra = []): array
    {
        return array_merge([
            'modo_volumen' => 'total',
            'volumen_total' => null,
            'unidad' => 'caja',
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-06-28',
            'metas' => [],
            'usa_calibres' => true,
            'calibres' => [['calibre_id' => 10, 'volumen_total' => 100], ['calibre_id' => 11, 'volumen_total' => 50]],
        ], $extra);
    }

    public function test_volumen_total_por_calibre_valido(): void
    {
        ReglasConvenio::validarVolumen($this->datos());
        $this->assertTrue(true);
    }

    public function test_volumen_total_por_calibre_debe_ser_positivo_y_valido(): void
    {
        $e = $this->errores(fn () => ReglasConvenio::validarVolumen($this->datos([
            'calibres' => [['calibre_id' => 10, 'volumen_total' => 0], ['calibre_id' => 11, 'volumen_total' => 2.5]],
        ])));
        $this->assertSame('Indica el volumen total pactado (mayor a cero).', $e['calibres.0.volumen_total']);
        $this->assertSame('En cajas la cantidad debe ser un número entero.', $e['calibres.1.volumen_total']);

        $e = $this->errores(fn () => ReglasConvenio::validarVolumen($this->datos([
            'unidad' => 'kg',
            'calibres' => [['calibre_id' => 10, 'volumen_total' => 1.234], ['calibre_id' => 11, 'volumen_total' => null]],
        ])));
        $this->assertSame('En kilos la cantidad admite hasta dos decimales.', $e['calibres.0.volumen_total']);
        $this->assertArrayHasKey('calibres.1.volumen_total', $e);
    }

    public function test_volumen_total_por_calibre_no_admite_metas(): void
    {
        $e = $this->errores(fn () => ReglasConvenio::validarVolumen($this->datos([
            'metas' => [['semana' => '2026-06-01', 'cantidad' => 5, 'calibre_id' => 10]],
        ])));
        $this->assertSame(['metas' => 'En volumen total no se capturan metas semanales.'], $e);

        // Metas guardadas: no vienen en la petición, no se reporta.
        ReglasConvenio::validarVolumen($this->datos(['metas' => [['semana' => '2026-06-01', 'cantidad' => 5, 'calibre_id' => 10]]]), true);
        $this->assertTrue(true);
    }

    public function test_semanal_por_calibre_valido(): void
    {
        ReglasConvenio::validarVolumen($this->datos([
            'modo_volumen' => 'semanal',
            'metas' => [
                ['semana' => '2026-06-01', 'cantidad' => 5, 'calibre_id' => 10],
                ['semana' => '2026-06-01', 'cantidad' => 7, 'calibre_id' => 11],
                ['semana' => '2026-06-08', 'cantidad' => 9, 'calibre_id' => 10],
            ],
        ]));
        $this->assertTrue(true);
    }

    public function test_semanal_cada_calibre_necesita_una_meta(): void
    {
        $e = $this->errores(fn () => ReglasConvenio::validarVolumen($this->datos([
            'modo_volumen' => 'semanal',
            'metas' => [['semana' => '2026-06-01', 'cantidad' => 5, 'calibre_id' => 10]],
        ])));
        $this->assertSame(['calibres.1.calibre_id' => 'Captura al menos una meta semanal para este calibre.'], $e);
    }

    public function test_semanal_meta_exige_calibre_elegido(): void
    {
        $e = $this->errores(fn () => ReglasConvenio::validarVolumen($this->datos([
            'modo_volumen' => 'semanal',
            'metas' => [
                ['semana' => '2026-06-01', 'cantidad' => 5, 'calibre_id' => 10],
                ['semana' => '2026-06-01', 'cantidad' => 5, 'calibre_id' => 11],
                ['semana' => '2026-06-08', 'cantidad' => 5],
                ['semana' => '2026-06-08', 'cantidad' => 5, 'calibre_id' => 99],
            ],
        ])));
        $this->assertArrayHasKey('metas.2.calibre_id', $e);
        $this->assertArrayHasKey('metas.3.calibre_id', $e);
        $this->assertArrayNotHasKey('metas.0.calibre_id', $e);
    }

    public function test_semanal_par_semana_calibre_no_se_repite(): void
    {
        $e = $this->errores(fn () => ReglasConvenio::validarVolumen($this->datos([
            'modo_volumen' => 'semanal',
            'metas' => [
                ['semana' => '2026-06-01', 'cantidad' => 5, 'calibre_id' => 10],
                ['semana' => '2026-06-01', 'cantidad' => 7, 'calibre_id' => 11],
                ['semana' => '2026-06-01', 'cantidad' => 9, 'calibre_id' => 10],
            ],
        ])));
        $this->assertSame(['metas.2.semana' => 'Ya hay una meta de ese calibre en esa semana.'], $e);
    }

    public function test_semanal_por_calibre_aplica_reglas_de_semana_y_cantidad(): void
    {
        $e = $this->errores(fn () => ReglasConvenio::validarVolumen($this->datos([
            'modo_volumen' => 'semanal',
            'metas' => [
                ['semana' => '2026-06-02', 'cantidad' => 5, 'calibre_id' => 10],
                ['semana' => '2026-06-08', 'cantidad' => 2.5, 'calibre_id' => 11],
            ],
        ])));
        $this->assertSame('La semana debe indicarse con la fecha de su lunes.', $e['metas.0.semana']);
        $this->assertSame('En cajas la cantidad debe ser un número entero.', $e['metas.1.cantidad']);
    }

    public function test_volumen_clasico_sigue_igual(): void
    {
        ReglasConvenio::validarVolumen(['modo_volumen' => 'total', 'volumen_total' => 100, 'unidad' => 'caja',
            'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-28', 'metas' => []]);

        $e = $this->errores(fn () => ReglasConvenio::validarVolumen(['modo_volumen' => 'total', 'volumen_total' => null,
            'unidad' => 'caja', 'fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-06-28']));
        $this->assertArrayHasKey('volumen_total', $e);
    }
}
