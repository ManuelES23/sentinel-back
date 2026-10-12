<?php

namespace Tests\Feature\CRM;

use App\Support\CRM\PlantillasWhatsappPredeterminadas;
use App\Support\CRM\PlantillaWhatsappVariables;
use PHPUnit\Framework\TestCase;

class PlantillaWhatsappVariablesTest extends TestCase
{
    public function test_detecta_las_variables_usadas_sin_repetir_y_con_espacios(): void
    {
        $this->assertSame(
            ['nombre', 'folio'],
            PlantillaWhatsappVariables::usadas('Hola {{nombre}}, folio {{ folio }} y otra vez {{nombre}}'),
        );
    }

    public function test_las_desconocidas_son_las_que_no_estan_en_el_conjunto_cerrado(): void
    {
        $this->assertSame(
            ['cliente', 'Nombre', ''],
            PlantillaWhatsappVariables::desconocidas('{{cliente}} {{nombre}} {{Nombre}} {{}}'),
        );
        $this->assertSame([], PlantillaWhatsappVariables::desconocidas('Sin variables, {solo llaves sencillas}.'));
    }

    public function test_el_mensaje_de_error_nombra_la_variable(): void
    {
        $mensaje = PlantillaWhatsappVariables::mensajeDesconocidas(['cliente']);

        $this->assertStringContainsString('{{cliente}}', $mensaje);
        $this->assertStringContainsString('{{nombre}}', $mensaje);
    }

    public function test_sustituye_y_reporta_las_faltantes(): void
    {
        $this->assertSame(
            ['mensaje' => 'Hola Juan, folio , total ', 'variables_faltantes' => ['folio', 'total']],
            PlantillaWhatsappVariables::sustituir(
                'Hola {{nombre}}, folio {{folio}}, total {{total}}',
                ['nombre' => 'Juan', 'folio' => null, 'total' => '  '],
            ),
        );
    }

    public function test_las_plantillas_predeterminadas_solo_usan_variables_validas(): void
    {
        foreach (PlantillasWhatsappPredeterminadas::PLANTILLAS as $plantilla) {
            $this->assertSame([], PlantillaWhatsappVariables::desconocidas($plantilla['cuerpo']), $plantilla['nombre']);
            $this->assertLessThanOrEqual(1000, mb_strlen($plantilla['cuerpo']));
        }
    }
}
