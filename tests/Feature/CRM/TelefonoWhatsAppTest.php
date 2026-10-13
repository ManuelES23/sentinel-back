<?php

namespace Tests\Feature\CRM;

use App\Support\CRM\TelefonoWhatsApp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tabla de la spec 2026-10-12 §3 (CRM · WhatsApp Fase 0). Vive en
 * tests/Feature/CRM para que la corra el CI de CRM (--filter=CRM).
 */
class TelefonoWhatsAppTest extends TestCase
{
    public static function casos(): array
    {
        return [
            'nulo' => [null, null],
            'vacío' => ['', null],
            'solo espacios' => ['   ', null],
            'sin dígitos' => ['---', null],
            '10 dígitos con espacios' => ['662 123 4567', '526621234567'],
            '10 dígitos con paréntesis y guion' => ['(662) 123-4567', '526621234567'],
            '10 dígitos con puntos' => ['662.123.4567', '526621234567'],
            '+52' => ['+52 662 123 4567', '526621234567'],
            '+1 se respeta' => ['+1 415 555 2671', '14155552671'],
            'prefijo 00' => ['0052 662 123 4567', '526621234567'],
            '12 dígitos que empiezan con 52' => ['52 662 123 4567', '526621234567'],
            '13 dígitos que empiezan con 521' => ['521 662 123 4567', '526621234567'],
            '+52 1 (móvil antiguo)' => ['+52 1 662 123 4567', '526621234567'],
            'muy corto' => ['662 123', null],
            'más de 15 dígitos' => ['1234567890123456', null],
            'internacional de más de 15' => ['+1234567890123456', null],
            'internacional muy corto' => ['+52 123', null],
            'con extensión' => ['662 123 4567 ext. 12', null],
            'con letras' => ['tel 6621234567', null],
            '11 dígitos nacionales' => ['66212345678', null],
            '+ en medio' => ['662+1234567', null],
            // Ningún código de país empieza con 0.
            '+0052 (doble prefijo)' => ['+0052 662 123 4567', null],
            '000052 (doble prefijo)' => ['000052 6621234567', null],
            'solo ceros' => ['0000000000', null],
            // Caracteres invisibles y de formato que llegan al copiar y pegar.
            'marcas de dirección U+202A/U+202C' => ["\u{202A}+52 662 123 4567\u{202C}", '526621234567'],
            'BOM al inicio' => ["\u{FEFF}662 123 4567", '526621234567'],
            'espacio duro U+00A0' => ["662\u{00A0}123\u{00A0}4567", '526621234567'],
            'guiones tipográficos U+2010 a U+2015' => ["662\u{2010}123\u{2013}45\u{2015}67", '526621234567'],
            'UTF-8 inválido' => ["\xFF662 123 4567", null],
        ];
    }

    #[DataProvider('casos')]
    public function test_normaliza_como_dice_la_tabla_de_la_spec(?string $entrada, ?string $esperado): void
    {
        $this->assertSame($esperado, TelefonoWhatsApp::normalizar($entrada));
    }
}
