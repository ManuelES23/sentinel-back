<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmProspecto;
use App\Services\CRM\ContactoActividadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Migración de datos que pasa al cliente los contactos que quedaron en prospectos
 * convertidos ANTES del despliegue de «prospectos como empresas».
 */
class MoverContactosProspectosConvertidosMigracionTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const MIGRACION = 'migrations/2026_11_16_100100_mover_contactos_de_prospectos_convertidos.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
    }

    private function subir(): void
    {
        (require database_path(self::MIGRACION))->up();
    }

    private function prospecto(?int $empresaId = null, string $nombre = 'Rancho'): CrmProspecto
    {
        return CrmProspecto::create(['empresa_id' => $empresaId ?? $this->enterprise->id, 'nombre' => $nombre]);
    }

    private function cliente(CrmProspecto $prospecto, ?int $empresaId = null): CrmCliente
    {
        return CrmCliente::create([
            'empresa_id' => $empresaId ?? $prospecto->empresa_id, 'prospecto_id' => $prospecto->id, 'nombre' => $prospecto->nombre,
        ]);
    }

    private function contacto($entidad, string $nombre, bool $principal = false, ?int $empresaId = null): CrmContacto
    {
        return CrmContacto::create([
            'empresa_id' => $empresaId ?? $entidad->empresa_id, 'entidad_type' => $entidad::class, 'entidad_id' => $entidad->id,
            'nombre' => $nombre, 'es_principal' => $principal,
        ]);
    }

    private function dueno(CrmContacto $contacto): array
    {
        $contacto->refresh();

        return [$contacto->entidad_type, (int) $contacto->entidad_id];
    }

    public function test_mueve_los_contactos_del_prospecto_convertido_a_su_cliente(): void
    {
        $prospecto = $this->prospecto();
        $cliente = $this->cliente($prospecto);
        $principal = $this->contacto($prospecto, 'Ana', principal: true);
        $otro = $this->contacto($prospecto, 'Beto');

        $this->subir();

        $this->assertSame([CrmCliente::class, $cliente->id], $this->dueno($principal));
        $this->assertSame([CrmCliente::class, $cliente->id], $this->dueno($otro));
        $this->assertTrue($principal->es_principal, 'Sin principal en el cliente, el movido conserva el suyo.');
        $this->assertFalse($otro->es_principal);

        // Ahora son los que el selector ofrece y `resolver` acepta.
        $servicio = app(ContactoActividadService::class);
        $this->assertTrue($servicio->empresaDe($prospecto->fresh())->is($cliente));
        $this->assertSame($otro->id, $servicio->resolver($this->enterprise->id, $prospecto->fresh(), ['contacto_id' => $otro->id], true));
    }

    public function test_es_idempotente(): void
    {
        $prospecto = $this->prospecto();
        $cliente = $this->cliente($prospecto);
        $viejo = $this->contacto($prospecto, 'Ana', principal: true);

        $this->subir();
        // Un contacto nuevo del cliente entre corridas: la segunda no lo toca.
        $nuevo = $this->contacto($cliente, 'Nuevo');
        $totalAntes = CrmContacto::count();

        $this->subir();

        $this->assertSame($totalAntes, CrmContacto::count());
        $this->assertSame([CrmCliente::class, $cliente->id], $this->dueno($viejo));
        $this->assertSame([CrmCliente::class, $cliente->id], $this->dueno($nuevo));
        $this->assertTrue($viejo->es_principal);
        $this->assertFalse($nuevo->es_principal);
        $this->assertSame(1, CrmContacto::where('entidad_type', CrmCliente::class)->where('es_principal', true)->count());
    }

    public function test_si_el_cliente_ya_tiene_principal_los_movidos_dejan_de_serlo(): void
    {
        $prospecto = $this->prospecto();
        $cliente = $this->cliente($prospecto);
        $delCliente = $this->contacto($cliente, 'Principal del cliente', principal: true);
        $movido = $this->contacto($prospecto, 'Principal viejo', principal: true);

        $this->subir();

        $this->assertSame([CrmCliente::class, $cliente->id], $this->dueno($movido));
        $this->assertFalse($movido->es_principal);
        $this->assertTrue($delCliente->refresh()->es_principal);
        $this->assertSame(1, CrmContacto::where('entidad_type', CrmCliente::class)->where('entidad_id', $cliente->id)->where('es_principal', true)->count());
    }

    public function test_otra_empresa_y_prospectos_sin_cliente_quedan_intactos(): void
    {
        $otra = $this->crearOtraEmpresa();

        // Prospecto sin cliente.
        $suelto = $this->prospecto(nombre: 'Suelto');
        $delSuelto = $this->contacto($suelto, 'Del suelto', principal: true);

        // Cliente vigente de OTRA empresa que apunta (dato corrupto) a un prospecto de esta.
        $ajeno = $this->prospecto(nombre: 'Ajeno');
        $this->cliente($ajeno, $otra->id);
        $delAjeno = $this->contacto($ajeno, 'Del ajeno');

        // Prospecto cuyo único cliente está eliminado: no hay a dónde mover.
        $eliminado = $this->prospecto(nombre: 'Con cliente eliminado');
        $this->cliente($eliminado)->delete();
        $delEliminado = $this->contacto($eliminado, 'Del eliminado');

        // Conversión válida en la otra empresa: se mueve, sin mezclarse con la primera.
        $prospectoOtra = $this->prospecto($otra->id, 'De la otra');
        $clienteOtra = $this->cliente($prospectoOtra);
        $contactoOtra = $this->contacto($prospectoOtra, 'De la otra empresa');

        $this->subir();

        $this->assertSame([CrmProspecto::class, $suelto->id], $this->dueno($delSuelto));
        $this->assertSame([CrmProspecto::class, $ajeno->id], $this->dueno($delAjeno));
        $this->assertSame([CrmProspecto::class, $eliminado->id], $this->dueno($delEliminado));
        $this->assertSame([CrmCliente::class, $clienteOtra->id], $this->dueno($contactoOtra));
        $this->assertSame($otra->id, (int) $contactoOtra->empresa_id);
    }

    public function test_con_varios_clientes_gana_el_de_menor_id(): void
    {
        $prospecto = $this->prospecto();
        $primero = $this->cliente($prospecto);
        $this->cliente($prospecto);
        $contacto = $this->contacto($prospecto, 'Ana');

        $this->subir();

        $this->assertSame([CrmCliente::class, $primero->id], $this->dueno($contacto));
        $this->assertTrue(app(ContactoActividadService::class)->empresaDe($prospecto->fresh())->is($primero));
    }

    public function test_down_no_hace_nada(): void
    {
        $prospecto = $this->prospecto();
        $cliente = $this->cliente($prospecto);
        $contacto = $this->contacto($prospecto, 'Ana');
        $this->subir();

        (require database_path(self::MIGRACION))->down();

        $this->assertSame([CrmCliente::class, $cliente->id], $this->dueno($contacto));
    }
}
