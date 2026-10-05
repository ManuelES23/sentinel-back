<?php

namespace Tests\Feature\ActivosFijos;

use App\Models\Enterprise;
use Database\Seeders\MasterStructureSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class SembradoPrefijosActivosTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_instalacion_nueva_siembra_los_prefijos_de_codigo_de_activos(): void
    {
        // El seeder completo no corre sobre el esquema SQLite de pruebas (users.is_admin);
        // se ejercita solo la creación de empresas, que es lo que importa a Activos Fijos.
        $comando = \Mockery::mock(Command::class)->shouldIgnoreMissing();
        $seeder = new MasterStructureSeeder();
        $seeder->setCommand($comando);

        $metodo = new ReflectionMethod($seeder, 'createEnterprises');
        $metodo->setAccessible(true);
        $metodo->invoke($seeder);
        $metodo->invoke($seeder); // idempotente

        $this->assertSame('SF', Enterprise::where('slug', 'splendidfarms')->value('asset_code_prefix'));
        $this->assertSame('SP', Enterprise::where('slug', 'splendidbyporvenir')->value('asset_code_prefix'));
        $this->assertSame('GE', Enterprise::where('slug', 'grupoesplendido')->value('asset_code_prefix'));
        $this->assertSame(3, Enterprise::count());
    }
}
