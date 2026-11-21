<?php

namespace Tests\Feature\SplendidFarms\Administration\Planeacion;

use App\Models\Cultivo;
use App\Models\Planeacion\Presentacion;
use App\Models\Variedad;
use App\Services\Planeacion\CatalogoPlaneacion;
use App\Services\Planeacion\RealEmpacado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Real empacado de la semana (lunes 2026-11-23 a domingo 2026-11-29): los
 * pallets de produccion_empaque traducidos por receta a renglones
 * presentación × grupo de balance × tipo de empaque, con cajas por día.
 */
class RealEmpacadoTest extends TestCase
{
    use ConstruyePrograma;
    use RefreshDatabase;

    private const LUNES = '2026-11-23';

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearElote();
    }

    private function real(?int $cultivoId = null): array
    {
        return (new RealEmpacado(new CatalogoPlaneacion))->deLaSemana(self::LUNES, $cultivoId);
    }

    private function llave(string $presentacion, int|string $grupo, string $empaque): string
    {
        return RealEmpacado::llave($this->presentacion($presentacion)->id, $grupo, $this->tipoEmpaque($empaque)->id);
    }

    private function grupoId(string $nombre): int
    {
        return $this->grupo($nombre)->id;
    }

    public function test_un_pallet_con_receta_cae_en_su_renglon_por_dia(): void
    {
        $receta = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($receta, 'Bulk', 'RPC');
        $this->pallet('2026-11-25', $receta, 40, 'Amarillo');

        $real = $this->real();

        $llave = $this->llave('Bulk', $this->grupoId('Amarillo / Bicolor'), 'RPC');
        $this->assertSame([$llave], array_keys($real['renglones']));
        $this->assertSame([
            'presentacion_id' => $this->presentacion('Bulk')->id,
            'grupo_id' => $this->grupoId('Amarillo / Bicolor'),
            'tipo_empaque_id' => $this->tipoEmpaque('RPC')->id,
            'cajas' => [0, 0, 40, 0, 0, 0, 0],
        ], $real['renglones'][$llave]);
        $this->assertSame(['cajas' => [0, 0, 0, 0, 0, 0, 0], 'pallets' => 0, 'recetas' => []], $real['sin_clasificar']);
    }

    public function test_la_combinacion_con_variedad_gana_a_la_generica(): void
    {
        $receta = $this->recetaEmpaque('Caja mixta');
        $this->combinacion($receta, 'Bulk', 'RPC');
        $this->combinacion($receta, 'Tray', 'Cartón', 'Blanco');

        $this->pallet('2026-11-23', $receta, 30, 'Blanco');
        $this->pallet('2026-11-23', $receta, 20, 'Amarillo');

        $real = $this->real();

        $this->assertSame([0 => 30], array_filter($real['renglones'][$this->llave('Tray', $this->grupoId('Blanco'), 'Cartón')]['cajas']));
        $this->assertSame([0 => 20], array_filter($real['renglones'][$this->llave('Bulk', $this->grupoId('Amarillo / Bicolor'), 'RPC')]['cajas']));
        $this->assertCount(2, $real['renglones']);
        $this->assertSame(0, $real['sin_clasificar']['pallets']);
    }

    public function test_dos_combinaciones_igual_de_especificas_es_ambiguo_y_va_a_sin_clasificar(): void
    {
        $generica = $this->recetaEmpaque('Genérica doble');
        $this->combinacion($generica, 'Bulk', 'RPC');
        $this->combinacion($generica, 'Tray', 'RPC');

        $especifica = $this->recetaEmpaque('Específica doble');
        $this->combinacion($especifica, 'Bulk', 'RPC');
        $this->combinacion($especifica, 'Bulk', 'Cartón', 'Blanco');
        $this->combinacion($especifica, 'Tray', 'Cartón', 'Blanco');

        $this->pallet('2026-11-24', $generica, 10, 'Amarillo');
        $this->pallet('2026-11-24', $especifica, 15, 'Blanco');

        $real = $this->real();

        $this->assertSame([], $real['renglones']);
        $this->assertSame([0, 25, 0, 0, 0, 0, 0], $real['sin_clasificar']['cajas']);
        $this->assertSame(2, $real['sin_clasificar']['pallets']);
        $this->assertEqualsCanonicalizing([
            ['recipe_id' => $generica->id, 'nombre' => 'Genérica doble', 'cajas' => 10],
            ['recipe_id' => $especifica->id, 'nombre' => 'Específica doble', 'cajas' => 15],
        ], $real['sin_clasificar']['recetas']);
    }

    public function test_el_grupo_sale_de_la_variedad_del_pallet(): void
    {
        $morado = Variedad::create(['cultivo_id' => $this->cultivo->id, 'nombre' => 'Morado', 'user_id' => $this->autor->id]);
        $receta = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($receta, 'Bulk', 'RPC');

        $this->pallet('2026-11-26', $receta, 12, 'Bicolor');
        $this->pallet('2026-11-26', $receta, 8, 'Amarillo');
        $this->pallet('2026-11-27', $receta, 5, $morado);

        $real = $this->real();

        $amarilloBicolor = $real['renglones'][$this->llave('Bulk', $this->grupoId('Amarillo / Bicolor'), 'RPC')];
        $this->assertSame([0, 0, 0, 20, 0, 0, 0], $amarilloBicolor['cajas']);

        $implicito = $real['renglones'][$this->llave('Bulk', 'v'.$morado->id, 'RPC')];
        $this->assertSame('v'.$morado->id, $implicito['grupo_id']);
        $this->assertSame([0, 0, 0, 0, 5, 0, 0], $implicito['cajas']);
    }

    public function test_un_pallet_mixto_se_reparte_por_sus_detalles(): void
    {
        $bulk = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($bulk, 'Bulk', 'RPC');
        $tray = $this->recetaEmpaque('Tray RPC');
        $this->combinacion($tray, 'Tray', 'RPC');

        // El pallet trae su propia receta (la de Bulk), pero mandan los detalles.
        $this->pallet('2026-11-28', $bulk, 0, 'Amarillo', [], [[$bulk, 18], [$tray, 22]]);

        $real = $this->real();

        $grupo = $this->grupoId('Amarillo / Bicolor');
        $this->assertSame([0, 0, 0, 0, 0, 18, 0], $real['renglones'][$this->llave('Bulk', $grupo, 'RPC')]['cajas']);
        $this->assertSame([0, 0, 0, 0, 0, 22, 0], $real['renglones'][$this->llave('Tray', $grupo, 'RPC')]['cajas']);
    }

    public function test_un_detalle_sin_receta_hereda_la_del_pallet(): void
    {
        // Cola de Bulk completada con una entrada sin receta propia.
        $bulk = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($bulk, 'Bulk', 'RPC');
        $this->pallet('2026-11-24', $bulk, 0, 'Amarillo', ['is_cola' => true], [[$bulk, 30], [null, 12]]);

        $real = $this->real();

        $this->assertSame([0, 42, 0, 0, 0, 0, 0], $real['renglones'][$this->llave('Bulk', $this->grupoId('Amarillo / Bicolor'), 'RPC')]['cajas']);
        $this->assertSame(0, $real['sin_clasificar']['pallets']);
    }

    public function test_el_remanente_fuera_de_los_detalles_va_con_la_receta_del_pallet(): void
    {
        $bulk = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($bulk, 'Bulk', 'RPC');
        $tray = $this->recetaEmpaque('Tray RPC');
        $this->combinacion($tray, 'Tray', 'RPC');

        $this->pallet('2026-11-25', $bulk, 0, 'Amarillo', ['total_cajas' => 50], [[$tray, 20]]);

        $real = $this->real();

        $grupo = $this->grupoId('Amarillo / Bicolor');
        $this->assertSame([0, 0, 20, 0, 0, 0, 0], $real['renglones'][$this->llave('Tray', $grupo, 'RPC')]['cajas']);
        $this->assertSame([0, 0, 30, 0, 0, 0, 0], $real['renglones'][$this->llave('Bulk', $grupo, 'RPC')]['cajas']);
    }

    public function test_sin_remanente_cuando_los_detalles_suman_el_total_o_mas(): void
    {
        $bulk = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($bulk, 'Bulk', 'RPC');
        $tray = $this->recetaEmpaque('Tray RPC');
        $this->combinacion($tray, 'Tray', 'RPC');

        $this->pallet('2026-11-26', $bulk, 0, 'Amarillo', ['total_cajas' => 20], [[$tray, 20]]);   // igual
        $this->pallet('2026-11-27', $bulk, 0, 'Amarillo', ['total_cajas' => 10], [[$tray, 25]]);   // de más

        $real = $this->real();

        $grupo = $this->grupoId('Amarillo / Bicolor');
        $this->assertSame([$this->llave('Tray', $grupo, 'RPC')], array_keys($real['renglones']));
        $this->assertSame([0, 0, 0, 20, 25, 0, 0], $real['renglones'][$this->llave('Tray', $grupo, 'RPC')]['cajas']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 0], $real['sin_clasificar']['cajas']);
    }

    public function test_lo_mostrado_cuadra_con_las_cajas_de_los_pallets(): void
    {
        $bulk = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($bulk, 'Bulk', 'RPC');
        $tray = $this->recetaEmpaque('Tray RPC');
        $this->combinacion($tray, 'Tray', 'RPC');
        $suelta = $this->recetaEmpaque('Sin combinación');

        $esperado = 0;
        $this->pallet('2026-11-23', $bulk, 40, 'Amarillo');
        $esperado += 40;
        $this->pallet('2026-11-24', null, 33, 'Blanco');
        $esperado += 33;
        $this->pallet('2026-11-25', $suelta, 0, 'Blanco', [], [[$tray, 10], [null, 5]]);
        $esperado += 15;
        $this->pallet('2026-11-26', $bulk, 0, 'Bicolor', ['total_cajas' => 60], [[$tray, 20], [$suelta, 15]]);
        $esperado += 60;                                                  // 20 + 15 + remanente 25
        $this->pallet('2026-11-29', $bulk, 0, 'Amarillo', ['total_cajas' => 10], [[$tray, 18]]);
        $esperado += 18;                                                  // los detalles mandan
        $this->pallet('2026-11-30', $bulk, 999, 'Amarillo');              // fuera de la semana

        $real = $this->real();

        $mostrado = array_sum($real['sin_clasificar']['cajas']);
        foreach ($real['renglones'] as $renglon) {
            $mostrado += array_sum($renglon['cajas']);
        }
        $this->assertSame($esperado, $mostrado);
        $this->assertSame(array_sum($real['sin_clasificar']['cajas']), array_sum(array_column($real['sin_clasificar']['recetas'], 'cajas')));
    }

    public function test_combinaciones_repetidas_no_son_ambiguas(): void
    {
        $receta = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($receta, 'Bulk', 'RPC');
        $this->combinacion($receta, 'Bulk', 'RPC');
        $otra = $this->recetaEmpaque('Bulk o Tray');
        $this->combinacion($otra, 'Bulk', 'RPC');
        $this->combinacion($otra, 'Bulk', 'Cartón');

        $this->pallet('2026-11-23', $receta, 9, 'Amarillo');
        $this->pallet('2026-11-23', $otra, 4, 'Amarillo');

        $real = $this->real();

        $this->assertSame([9, 0, 0, 0, 0, 0, 0], $real['renglones'][$this->llave('Bulk', $this->grupoId('Amarillo / Bicolor'), 'RPC')]['cajas']);
        $this->assertSame([['recipe_id' => $otra->id, 'nombre' => 'Bulk o Tray', 'cajas' => 4]], $real['sin_clasificar']['recetas']);
    }

    public function test_pallet_sin_receta_va_a_sin_clasificar_con_receta_nula(): void
    {
        $this->pallet('2026-11-23', null, 33, 'Blanco');
        $this->pallet('2026-11-24', null, 7, 'Amarillo');

        $real = $this->real();

        $this->assertSame([], $real['renglones']);
        $this->assertSame([33, 7, 0, 0, 0, 0, 0], $real['sin_clasificar']['cajas']);
        $this->assertSame(2, $real['sin_clasificar']['pallets']);
        $this->assertSame([['recipe_id' => null, 'nombre' => null, 'cajas' => 40]], $real['sin_clasificar']['recetas']);
    }

    public function test_receta_sin_combinacion_va_a_sin_clasificar_con_su_nombre(): void
    {
        $receta = $this->recetaEmpaque('Caja sin configurar');
        $this->pallet('2026-11-29', $receta, 50, 'Blanco');

        $real = $this->real();

        $this->assertSame([], $real['renglones']);
        $this->assertSame([0, 0, 0, 0, 0, 0, 50], $real['sin_clasificar']['cajas']);
        $this->assertSame(1, $real['sin_clasificar']['pallets']);
        $this->assertSame([['recipe_id' => $receta->id, 'nombre' => 'Caja sin configurar', 'cajas' => 50]], $real['sin_clasificar']['recetas']);
    }

    public function test_un_mixto_con_detalles_sin_clasificar_cuenta_un_pallet(): void
    {
        $sinCombinacion = $this->recetaEmpaque('Sin combinación');
        $this->pallet('2026-11-25', null, 0, 'Blanco', [], [[$sinCombinacion, 10], [null, 5]]);

        $real = $this->real();

        $this->assertSame(1, $real['sin_clasificar']['pallets']);
        $this->assertSame([0, 0, 15, 0, 0, 0, 0], $real['sin_clasificar']['cajas']);
        $this->assertEqualsCanonicalizing([
            ['recipe_id' => $sinCombinacion->id, 'nombre' => 'Sin combinación', 'cajas' => 10],
            ['recipe_id' => null, 'nombre' => null, 'cajas' => 5],
        ], $real['sin_clasificar']['recetas']);
    }

    public function test_solo_cuentan_los_pallets_de_la_semana(): void
    {
        $receta = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($receta, 'Bulk', 'RPC');

        $this->pallet('2026-11-29', $receta, 11, 'Amarillo');                                  // domingo: sí
        $this->pallet('2026-11-30', $receta, 100, 'Amarillo');                                 // lunes siguiente: no
        $this->pallet('2026-11-22', $receta, 200, 'Amarillo');                                 // domingo anterior: no
        $this->pallet('2026-11-24', $receta, 300, 'Amarillo')->delete();                       // borrado: no
        $this->pallet('2026-11-24', $receta, 3, 'Amarillo', ['status' => 'en_almacen']);
        $this->pallet('2026-11-24', $receta, 4, 'Amarillo', ['status' => 'embarcado']);
        $this->pallet('2026-11-24', $receta, 6, 'Amarillo', ['is_cola' => true]);

        $real = $this->real();

        $this->assertSame(
            [0, 13, 0, 0, 0, 0, 11],
            $real['renglones'][$this->llave('Bulk', $this->grupoId('Amarillo / Bicolor'), 'RPC')]['cajas'],
        );
        $this->assertSame(0, $real['sin_clasificar']['pallets']);
    }

    public function test_con_cultivo_filtrado_ignora_otros_cultivos(): void
    {
        $mango = Cultivo::create(['nombre' => 'Mango']);
        $kent = Variedad::create(['cultivo_id' => $mango->id, 'nombre' => 'Kent', 'user_id' => $this->autor->id]);
        $cajaMango = Presentacion::create(['cultivo_id' => $mango->id, 'nombre' => 'Caja 4 kg', 'cajas_full' => 1000, 'cajas_sencillo' => 500, 'orden' => 1, 'is_active' => true]);

        $elote = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($elote, 'Bulk', 'RPC');
        $recetaMango = $this->recetaEmpaque('Mango 4 kg');
        $this->combinacion($recetaMango, $cajaMango, 'Cartón');

        $this->pallet('2026-11-23', $elote, 10, 'Amarillo');
        $this->pallet('2026-11-23', $recetaMango, 20, $kent);
        $this->pallet('2026-11-23', null, 30, $kent);        // sin clasificar, de mango
        $this->pallet('2026-11-23', null, 40, 'Blanco');     // sin clasificar, de elote

        $soloElote = $this->real($this->cultivo->id);
        $this->assertSame([$this->llave('Bulk', $this->grupoId('Amarillo / Bicolor'), 'RPC')], array_keys($soloElote['renglones']));
        $this->assertSame([40, 0, 0, 0, 0, 0, 0], $soloElote['sin_clasificar']['cajas']);
        $this->assertSame(1, $soloElote['sin_clasificar']['pallets']);

        $todos = $this->real();
        $this->assertCount(2, $todos['renglones']);
        $this->assertSame([20, 0, 0, 0, 0, 0, 0], $todos['renglones'][RealEmpacado::llave($cajaMango->id, 'v'.$kent->id, $this->tipoEmpaque('Cartón')->id)]['cajas']);
        $this->assertSame([70, 0, 0, 0, 0, 0, 0], $todos['sin_clasificar']['cajas']);
        $this->assertSame(2, $todos['sin_clasificar']['pallets']);
    }

    public function test_la_consulta_no_crece_con_los_pallets(): void
    {
        $bulk = $this->recetaEmpaque('Bulk RPC');
        $this->combinacion($bulk, 'Bulk', 'RPC');
        $tray = $this->recetaEmpaque('Tray RPC');
        $this->combinacion($tray, 'Tray', 'RPC');
        $suelta = $this->recetaEmpaque('Sin combinación');

        $crear = function (int $cuantos) use ($bulk, $tray, $suelta): void {
            for ($i = 0; $i < $cuantos; $i++) {
                $dia = sprintf('2026-11-%02d', 23 + $i % 7);
                match ($i % 3) {
                    0 => $this->pallet($dia, $bulk, 10, 'Amarillo'),
                    1 => $this->pallet($dia, null, 0, 'Blanco', [], [[$tray, 4], [$suelta, 6]]),
                    2 => $this->pallet($dia, $suelta, 5, 'Bicolor'),
                };
            }
        };
        $contar = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->real();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $crear(3);
        $conTres = $contar();
        $crear(27);
        $conTreinta = $contar();

        $this->assertSame($conTres, $conTreinta);
        // Pallets, sus detalles, combinaciones, nombres de recetas y los tres
        // catálogos de CatalogoPlaneacion (variedades, grupos y sus variedades).
        $this->assertLessThanOrEqual(7, $conTreinta);
    }
}
