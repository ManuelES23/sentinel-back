<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmConfiguracionComercial;
use App\Models\CRM\CrmContacto;
use App\Models\CRM\CrmEmpresaExterna;
use App\Models\CRM\CrmOportunidad;
use App\Models\CRM\CrmProspecto;
use App\Services\CRM\ContactoActividadService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * Servicio único que decide si una actividad exige contacto, resuelve la
 * «empresa de la actividad» y valida/crea el contacto.
 */
class ContactoActividadServiceTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private ContactoActividadService $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        $this->servicio = app(ContactoActividadService::class);
    }

    private function encender(): void
    {
        CrmConfiguracionComercial::paraEmpresa($this->enterprise->id)->update(['prospecto_como_empresa' => true]);
    }

    private function prospecto(?int $empresaId = null, string $nombre = 'Prospecto'): CrmProspecto
    {
        return CrmProspecto::create(['empresa_id' => $empresaId ?? $this->enterprise->id, 'nombre' => $nombre, 'estatus' => 'nuevo']);
    }

    private function cliente(?int $empresaId = null, ?int $prospectoId = null): CrmCliente
    {
        return CrmCliente::create([
            'empresa_id' => $empresaId ?? $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
            'prospecto_id' => $prospectoId,
        ]);
    }

    private function contacto(Model $entidad, string $nombre = 'Ana', bool $principal = false): CrmContacto
    {
        return CrmContacto::create([
            'empresa_id' => $entidad->empresa_id, 'entidad_type' => $entidad::class, 'entidad_id' => $entidad->id,
            'nombre' => $nombre, 'es_principal' => $principal,
        ]);
    }

    private function oportunidad(array $datos): CrmOportunidad
    {
        return CrmOportunidad::create($datos + [
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Op', 'etapa' => 'calificado',
        ]);
    }

    private function assertErrorContacto(callable $accion, string $mensaje): void
    {
        try {
            $accion();
            $this->fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame([$mensaje], $e->errors()['contacto_id'] ?? null);
        }
    }

    public function test_empresa_de_oportunidad_con_cliente_es_el_cliente(): void
    {
        $cliente = $this->cliente();
        $op = $this->oportunidad(['cliente_id' => $cliente->id]);

        $empresa = $this->servicio->empresaDe($op);

        $this->assertInstanceOf(CrmCliente::class, $empresa);
        $this->assertSame($cliente->id, $empresa->id);
    }

    public function test_empresa_de_oportunidad_de_prospecto_convertido_es_su_cliente(): void
    {
        // Oportunidad perdida que se quedó en el prospecto después de convertirlo.
        $prospecto = $this->prospecto();
        $cliente = $this->cliente(null, $prospecto->id);
        $op = $this->oportunidad(['prospecto_id' => $prospecto->id, 'etapa' => 'perdido']);

        $empresa = $this->servicio->empresaDe($op);

        $this->assertInstanceOf(CrmCliente::class, $empresa);
        $this->assertSame($cliente->id, $empresa->id);
    }

    public function test_empresa_de_prospecto_sin_convertir_es_el_prospecto_y_de_cliente_es_el_mismo(): void
    {
        $prospecto = $this->prospecto();
        $cliente = $this->cliente();
        $op = $this->oportunidad(['prospecto_id' => $prospecto->id]);

        $this->assertTrue($this->servicio->empresaDe($prospecto)->is($prospecto));
        $this->assertTrue($this->servicio->empresaDe($cliente)->is($cliente));
        $this->assertTrue($this->servicio->empresaDe($op)->is($prospecto));
        $this->assertNull($this->servicio->empresaDe(null));
    }

    public function test_empresa_de_empresa_externa_es_ella(): void
    {
        $externa = CrmEmpresaExterna::create(['empresa_id' => $this->enterprise->id, 'razon_social' => 'Externa SA']);

        $this->assertTrue($this->servicio->empresaDe($externa)->is($externa));
    }

    public function test_exige_solo_con_bandera_tipo_y_fuente(): void
    {
        $op = $this->oportunidad(['cliente_id' => $this->cliente()->id]);
        $externa = CrmEmpresaExterna::create(['empresa_id' => $this->enterprise->id, 'razon_social' => 'Externa SA']);
        $empresaId = $this->enterprise->id;

        // Bandera apagada.
        $this->assertFalse($this->servicio->exige($empresaId, $op, 'llamada'));

        $this->encender();

        $this->assertTrue($this->servicio->exige($empresaId, $op, 'llamada'));
        $this->assertTrue($this->servicio->exige($empresaId, $op, 'reunion', 'whatsapp_enlace'));
        $this->assertTrue($this->servicio->exige($empresaId, $this->prospecto(), 'visita'));
        $this->assertTrue($this->servicio->exige($empresaId, $this->cliente(), 'correo'));
        $this->assertFalse($this->servicio->exige($empresaId, $op, 'nota'));
        $this->assertFalse($this->servicio->exige($empresaId, $op, null));
        $this->assertFalse($this->servicio->exige($empresaId, $op, 'llamada', 'sistema'));
        $this->assertFalse($this->servicio->exige($empresaId, $op, 'llamada', 'dialpad'));
        $this->assertFalse($this->servicio->exige($empresaId, $externa, 'llamada'));
        $this->assertFalse($this->servicio->exige($empresaId, null, 'llamada'));
    }

    public function test_reglas_validan_el_payload_del_contacto(): void
    {
        $reglas = $this->servicio->reglas();

        $this->assertSame('nullable|integer', $reglas['contacto_id']);
        $this->assertSame('nullable|array', $reglas['contacto_nuevo']);
        $this->assertSame('required_with:contacto_nuevo|string|max:255', $reglas['contacto_nuevo.nombre']);
        $this->assertSame('nullable|email|max:255', $reglas['contacto_nuevo.email']);
        $this->assertSame('nullable|string|max:50', $reglas['contacto_nuevo.telefono']);
        $this->assertSame('nullable|string|max:255', $reglas['contacto_nuevo.cargo']);
    }

    public function test_resolver_devuelve_el_contacto_elegido(): void
    {
        $this->encender();
        $cliente = $this->cliente();
        $contacto = $this->contacto($cliente);
        $op = $this->oportunidad(['cliente_id' => $cliente->id]);

        $id = $this->servicio->resolver($this->enterprise->id, $op, ['contacto_id' => $contacto->id], true);

        $this->assertSame($contacto->id, $id);
    }

    public function test_resolver_acepta_contacto_del_cliente_en_oportunidad_de_prospecto_convertido(): void
    {
        $this->encender();
        $prospecto = $this->prospecto();
        $cliente = $this->cliente(null, $prospecto->id);
        $contacto = $this->contacto($cliente);
        $op = $this->oportunidad(['prospecto_id' => $prospecto->id, 'etapa' => 'perdido']);

        $id = $this->servicio->resolver($this->enterprise->id, $op, ['contacto_id' => $contacto->id], true);

        $this->assertSame($contacto->id, $id);
    }

    public function test_resolver_rechaza_contacto_de_otra_empresa(): void
    {
        $this->encender();
        $uno = $this->prospecto(null, 'Uno');
        $otro = $this->prospecto(null, 'Otro');
        $ajeno = $this->contacto($otro);

        $this->assertErrorContacto(
            fn () => $this->servicio->resolver($this->enterprise->id, $uno, ['contacto_id' => $ajeno->id], true),
            'El contacto no pertenece a esta empresa.',
        );
    }

    public function test_resolver_rechaza_contacto_de_otro_tenant(): void
    {
        $this->encender();
        $otraEmpresa = $this->crearOtraEmpresa();
        $ajeno = $this->contacto($this->prospecto($otraEmpresa->id, 'De otro tenant'));
        $propio = $this->prospecto();

        $this->assertErrorContacto(
            fn () => $this->servicio->resolver($this->enterprise->id, $propio, ['contacto_id' => $ajeno->id], true),
            'El contacto no pertenece a esta empresa.',
        );
    }

    public function test_resolver_sin_bandera_valida_el_contacto_que_venga_y_devuelve_null_si_no_viene(): void
    {
        $uno = $this->prospecto(null, 'Uno');
        $ajeno = $this->contacto($this->prospecto(null, 'Otro'));
        $propio = $this->contacto($uno);

        $this->assertNull($this->servicio->resolver($this->enterprise->id, $uno, [], false));
        $this->assertSame($propio->id, $this->servicio->resolver($this->enterprise->id, $uno, ['contacto_id' => $propio->id], false));
        $this->assertErrorContacto(
            fn () => $this->servicio->resolver($this->enterprise->id, $uno, ['contacto_id' => $ajeno->id], false),
            'El contacto no pertenece a esta empresa.',
        );
    }

    public function test_resolver_crea_contacto_nuevo_principal_si_no_habia(): void
    {
        $this->encender();
        $cliente = $this->cliente();
        $op = $this->oportunidad(['cliente_id' => $cliente->id]);

        $id = $this->servicio->resolver($this->enterprise->id, $op, [
            'contacto_nuevo' => ['nombre' => 'Luis Gómez', 'cargo' => 'Compras', 'email' => 'luis@example.com', 'telefono' => '6671234567'],
        ], true);

        $contacto = CrmContacto::findOrFail($id);
        $this->assertSame(CrmCliente::class, $contacto->entidad_type);
        $this->assertSame($cliente->id, (int) $contacto->entidad_id);
        $this->assertSame($this->enterprise->id, (int) $contacto->empresa_id);
        $this->assertSame('Luis Gómez', $contacto->nombre);
        $this->assertSame('Compras', $contacto->cargo);
        $this->assertTrue($contacto->es_principal);
    }

    public function test_resolver_crea_contacto_nuevo_no_principal_si_ya_habia(): void
    {
        $this->encender();
        $cliente = $this->cliente();
        $existente = $this->contacto($cliente, 'Ana', true);

        $id = $this->servicio->resolver($this->enterprise->id, $cliente, ['contacto_nuevo' => ['nombre' => 'Beto']], true);

        $nuevo = CrmContacto::findOrFail($id);
        $this->assertFalse($nuevo->es_principal);
        $this->assertTrue($existente->fresh()->es_principal);
    }

    public function test_resolver_contacto_nuevo_en_oportunidad_de_prospecto_convertido_cuelga_del_cliente(): void
    {
        $this->encender();
        $prospecto = $this->prospecto();
        $cliente = $this->cliente(null, $prospecto->id);
        $op = $this->oportunidad(['prospecto_id' => $prospecto->id, 'etapa' => 'perdido']);

        $id = $this->servicio->resolver($this->enterprise->id, $op, ['contacto_nuevo' => ['nombre' => 'Carla']], true);

        $contacto = CrmContacto::findOrFail($id);
        $this->assertSame(CrmCliente::class, $contacto->entidad_type);
        $this->assertSame($cliente->id, (int) $contacto->entidad_id);
    }

    public function test_resolver_obligatorio_sin_contacto_falla(): void
    {
        $this->encender();
        $prospecto = $this->prospecto();

        $this->assertErrorContacto(
            fn () => $this->servicio->resolver($this->enterprise->id, $prospecto, [], true),
            'Elige con quién hablaste o agrega un contacto.',
        );
    }

    public function test_resolver_con_ambos_falla(): void
    {
        $this->encender();
        $prospecto = $this->prospecto();
        $contacto = $this->contacto($prospecto);

        $this->assertErrorContacto(
            fn () => $this->servicio->resolver($this->enterprise->id, $prospecto, [
                'contacto_id' => $contacto->id, 'contacto_nuevo' => ['nombre' => 'Otro'],
            ], true),
            'Elige un contacto o agrega uno nuevo, no ambos.',
        );
        $this->assertSame(1, CrmContacto::count());
    }

    public function test_resolver_sin_entidad_con_contacto_falla(): void
    {
        $this->assertErrorContacto(
            fn () => $this->servicio->resolver($this->enterprise->id, null, ['contacto_nuevo' => ['nombre' => 'Otro']], false),
            'Este registro no tiene empresa para ligar un contacto.',
        );
        $this->assertErrorContacto(
            fn () => $this->servicio->resolver($this->enterprise->id, null, ['contacto_id' => 5], false),
            'Este registro no tiene empresa para ligar un contacto.',
        );
        $this->assertSame(0, CrmContacto::count());
    }

    public function test_resolver_trata_contacto_nuevo_vacio_como_ausente(): void
    {
        $cliente = $this->cliente();

        $this->assertNull($this->servicio->resolver($this->enterprise->id, $cliente, ['contacto_nuevo' => []], false));
        $this->assertErrorContacto(
            fn () => $this->servicio->resolver($this->enterprise->id, $cliente, ['contacto_nuevo' => []], true),
            'Elige con quién hablaste o agrega un contacto.',
        );
        $this->assertSame(0, CrmContacto::count());
    }

    public function test_empresa_de_prospecto_con_varios_clientes_ordena_por_id(): void
    {
        $prospecto = $this->prospecto();
        $primero = $this->cliente(null, $prospecto->id);
        $this->cliente(null, $prospecto->id);

        \DB::enableQueryLog();
        $empresa = $this->servicio->empresaDe($prospecto);
        $sql = collect(\DB::getQueryLog())->pluck('query')->implode(' | ');
        \DB::disableQueryLog();

        $this->assertSame($primero->id, $empresa->id);
        $this->assertMatchesRegularExpression('/order by [`"]id[`"] asc/', $sql);
    }

    public function test_resolver_entrega_el_contacto_creado_para_difundirlo_despues_del_commit(): void
    {
        $cliente = $this->cliente();
        $existente = $this->contacto($cliente, 'Ana', true);

        $creado = null;
        $this->servicio->resolver($this->enterprise->id, $cliente, ['contacto_id' => $existente->id], true, $creado);
        $this->assertNull($creado);

        $id = $this->servicio->resolver($this->enterprise->id, $cliente, ['contacto_nuevo' => ['nombre' => 'Beto']], true, $creado);
        $this->assertInstanceOf(CrmContacto::class, $creado);
        $this->assertSame($id, $creado->id);
    }
}
