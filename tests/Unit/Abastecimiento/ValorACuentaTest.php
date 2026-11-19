<?php

namespace Tests\Unit\Abastecimiento;

use App\Models\Abastecimiento\Convenio;
use App\Models\Abastecimiento\ConvenioPrecio;
use App\Models\Abastecimiento\Recepcion;
use App\Models\Abastecimiento\RecepcionCalibre;
use App\Models\CRM\CrmProductoCalibre;
use App\Services\Abastecimiento\CalculadoraLiquidacion;
use App\Services\Abastecimiento\ValorACuenta;
use Tests\TestCase;

/** Modelos armados en memoria: el valor a cuenta es puro y no toca la base de datos. */
class ValorACuentaTest extends TestCase
{
    private const JUMBO = 11;

    private const GRANDE = 12;

    /** @param  list<array{precio: float|int, pct?: ?float, calibre?: ?int}>  $precios */
    private function convenio(array $precios, string $unidad = 'caja', float $rezaga = 0, bool $porCalibre = false): Convenio
    {
        $convenio = new Convenio([
            'modalidad' => 'compra_directa',
            'unidad' => $unidad,
            'porcentaje_rezaga' => $rezaga,
            'usa_calibres' => $porCalibre,
        ]);
        $convenio->id = 1;
        $convenio->setRelation('precios', collect(array_map(fn (array $p) => new ConvenioPrecio([
            'convenio_id' => 1,
            'tipo_carga_id' => 1,
            'calibre_id' => $p['calibre'] ?? null,
            'precio' => $p['precio'],
            'porcentaje_productor' => $p['pct'] ?? null,
            'vigencia_inicio' => '2026-01-01',
        ]), $precios)));

        return $convenio;
    }

    /** @param  list<array{calibre_id: int, nombre: string, cantidad: float|int, kilos?: ?float, rezaga_kg?: ?float}>|null  $renglones */
    private function recepcion(float $cantidad = 0, ?float $kilos = null, ?float $rezaga = null, ?array $renglones = null, string $fecha = '2026-03-10'): Recepcion
    {
        $r = new Recepcion([
            'convenio_id' => 1,
            'fecha' => $fecha,
            'tipo_carga_id' => 1,
            'cantidad' => $cantidad,
            'kilos' => $kilos,
            'rezaga_kg' => $rezaga,
        ]);
        $r->id = 1;
        if ($renglones !== null) {
            $r->setRelation('renglones', collect(array_map(function (array $x) {
                $reng = new RecepcionCalibre([
                    'calibre_id' => $x['calibre_id'],
                    'cantidad' => $x['cantidad'],
                    'kilos' => $x['kilos'] ?? null,
                    'rezaga_kg' => $x['rezaga_kg'] ?? null,
                ]);
                $reng->setRelation('calibre', new CrmProductoCalibre(['nombre' => $x['nombre']]));

                return $reng;
            }, $renglones)));
        }

        return $r;
    }

    /** Jumbo con especial de 180 y Grande con el general de 150, % productor 100. */
    private function convenioPorCalibre(float $rezaga = 0): Convenio
    {
        return $this->convenio([
            ['precio' => 150],
            ['precio' => 180, 'calibre' => self::JUMBO],
        ], 'caja', $rezaga, true);
    }

    private function r1(?float $rezagaJumbo = 10, ?float $rezagaGrande = 4): Recepcion
    {
        return $this->recepcion(14, 140, $rezagaJumbo === null || $rezagaGrande === null ? null : $rezagaJumbo + $rezagaGrande, [
            ['calibre_id' => self::JUMBO, 'nombre' => 'Jumbo', 'cantidad' => 10, 'kilos' => 100, 'rezaga_kg' => $rezagaJumbo],
            ['calibre_id' => self::GRANDE, 'nombre' => 'Grande', 'cantidad' => 4, 'kilos' => 40, 'rezaga_kg' => $rezagaGrande],
        ]);
    }

    public function test_por_calibre_suma_cada_renglon_sin_rezaga(): void
    {
        $r = ValorACuenta::de($this->convenioPorCalibre(), $this->r1());

        $this->assertSame(['valor' => 2400.0, 'motivo' => null, 'sin_valor' => false], $r);
    }

    public function test_partes_clasico_es_la_cabecera_y_por_calibre_los_renglones(): void
    {
        $clasico = ValorACuenta::partes($this->convenio([['precio' => 10]]), $this->recepcion(3, 30.0, 1.0));
        $this->assertSame([['calibre_id' => null, 'cantidad' => 3.0, 'kilos' => 30.0, 'rezaga_kg' => 1.0, 'calibre' => null]], $clasico);

        $porCalibre = ValorACuenta::partes($this->convenioPorCalibre(), $this->r1());
        $this->assertSame([
            ['calibre_id' => self::JUMBO, 'cantidad' => 10.0, 'kilos' => 100.0, 'rezaga_kg' => 10.0, 'calibre' => 'Jumbo'],
            ['calibre_id' => self::GRANDE, 'cantidad' => 4.0, 'kilos' => 40.0, 'rezaga_kg' => 4.0, 'calibre' => 'Grande'],
        ], $porCalibre);

        $this->assertSame([], ValorACuenta::partes($this->convenioPorCalibre(), $this->recepcion(0, null, null, [])));
    }

    public function test_porcentaje_productor_se_aplica_por_parte_redondeando(): void
    {
        $convenio = $this->convenio([['precio' => 33.333, 'pct' => 50]]);

        $r = ValorACuenta::de($convenio, $this->recepcion(3));

        $this->assertSame(50.0, $r['valor']);
        $this->assertNull($r['motivo']);
    }

    public function test_por_kilo_usa_los_kilos(): void
    {
        $r = ValorACuenta::de($this->convenio([['precio' => 2.5]], 'kg'), $this->recepcion(7, 120));

        $this->assertSame(300.0, $r['valor']);
        $this->assertNull($r['motivo']);
    }

    public function test_sin_precio_da_cero_y_motivo(): void
    {
        $r = ValorACuenta::de($this->convenio([['precio' => 10]]), $this->recepcion(3, null, null, null, '2025-12-31'));

        $this->assertSame(['valor' => 0.0, 'motivo' => 'Sin precio vigente', 'sin_valor' => true], $r);
    }

    public function test_por_calibre_sin_precio_lista_los_calibres(): void
    {
        // Solo el especial de Jumbo: Grande no tiene precio (ni general).
        $convenio = $this->convenio([['precio' => 180, 'calibre' => self::JUMBO]], 'caja', 0, true);

        $r = ValorACuenta::de($convenio, $this->r1());

        $this->assertSame(['valor' => 0.0, 'motivo' => 'Sin precio vigente: Grande', 'sin_valor' => true], $r);
    }

    public function test_por_calibre_sin_renglones_es_la_primera_falla(): void
    {
        $r = ValorACuenta::de($this->convenioPorCalibre(), $this->recepcion(0, null, null, []));

        $this->assertSame(['valor' => 0.0, 'motivo' => 'Sin renglones por calibre', 'sin_valor' => true], $r);
    }

    public function test_faltan_los_kilos_en_convenio_por_kilo(): void
    {
        $r = ValorACuenta::de($this->convenio([['precio' => 2.5]], 'kg'), $this->recepcion(7, null));

        $this->assertSame(['valor' => 0.0, 'motivo' => 'Faltan los kilos', 'sin_valor' => true], $r);
    }

    public function test_falta_la_rezaga_conserva_el_valor(): void
    {
        $r = ValorACuenta::de($this->convenio([['precio' => 100]], 'caja', 5), $this->recepcion(10, 200, null));

        $this->assertSame(['valor' => 1000.0, 'motivo' => 'Falta la rezaga', 'sin_valor' => false], $r);
    }

    public function test_rezaga_cero_es_un_dato(): void
    {
        $r = ValorACuenta::de($this->convenio([['precio' => 100]], 'caja', 5), $this->recepcion(10, 200, 0));

        $this->assertSame(['valor' => 1000.0, 'motivo' => null, 'sin_valor' => false], $r);
    }

    public function test_por_calibre_un_renglon_sin_rezaga_basta_para_el_motivo(): void
    {
        $r = ValorACuenta::de($this->convenioPorCalibre(5), $this->r1(10, null));

        $this->assertSame(['valor' => 2400.0, 'motivo' => 'Falta la rezaga', 'sin_valor' => false], $r);
    }

    public function test_sin_rezaga_pactada_no_pide_rezaga(): void
    {
        $r = ValorACuenta::de($this->convenio([['precio' => 100]], 'caja', 0), $this->recepcion(10, 200, null));

        $this->assertSame(['valor' => 1000.0, 'motivo' => null, 'sin_valor' => false], $r);
    }

    public function test_el_motivo_es_la_primera_falla_en_orden(): void
    {
        // Convenio por kilo sin precio y sin kilos: gana «Sin precio vigente».
        $r = ValorACuenta::de($this->convenio([['precio' => 2.5]], 'kg'), $this->recepcion(7, null, null, null, '2025-12-31'));

        $this->assertSame('Sin precio vigente', $r['motivo']);
    }

    public function test_suma_de_valores_igual_al_calculado_sin_excedente(): void
    {
        // Rezaga real 14 / 200 = 7 %, aceptada 8 %: no hay excedente.
        $convenio = $this->convenioPorCalibre(8);
        $r1 = $this->r1();
        $r2 = $this->recepcion(6, 60, 0, [
            ['calibre_id' => self::GRANDE, 'nombre' => 'Grande', 'cantidad' => 6, 'kilos' => 60, 'rezaga_kg' => 0],
        ], '2026-03-11');
        $r2->id = 2;

        $suma = round(ValorACuenta::de($convenio, $r1)['valor'] + ValorACuenta::de($convenio, $r2)['valor'], 2);
        $calculado = CalculadoraLiquidacion::calcular($convenio, collect([$r1, $r2]))['monto_calculado'];

        $this->assertSame(3300.0, $suma);
        $this->assertSame($calculado, $suma);
    }
}
