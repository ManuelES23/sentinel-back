<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmActividad;
use App\Models\CRM\CrmAgenda;
use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmEtiquetaActividad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Catálogo de etiquetas de actividad por empresa (spec 2026-10-30 §8). */
class EtiquetaActividadControllerTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    private const URL = '/api/crm/etiquetas-actividad';

    private const MENSAJE_USADA = 'Esta etiqueta ya se usó; desactívala en lugar de borrarla.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
    }

    private function conPermisos(array $permisos = ['ver', 'crear', 'editar', 'eliminar']): void
    {
        $this->otorgarPermisosCrm('catalogos', 'etiquetas-actividad', $permisos);
    }

    private function etiqueta(array $datos = [], ?int $empresaId = null): CrmEtiquetaActividad
    {
        return CrmEtiquetaActividad::create(array_merge([
            'empresa_id' => $empresaId ?? $this->enterprise->id,
            'nombre' => 'Seguimiento',
        ], $datos));
    }

    private function enviar(string $metodo, string $uri = '', array $datos = [])
    {
        return $this->withHeaders($this->crmHeaders())->json($metodo, self::URL.$uri, $datos);
    }

    public function test_lectura_abierta_y_activas(): void
    {
        // Sin ningún permiso de catálogos: el selector de la agenda las necesita.
        $this->etiqueta(['nombre' => 'B', 'orden' => 2]);
        $this->etiqueta(['nombre' => 'A', 'orden' => 1]);
        $this->etiqueta(['nombre' => 'Apagada', 'orden' => 3, 'activo' => false]);
        $this->etiqueta(['nombre' => 'Ajena'], $this->crearOtraEmpresa()->id);

        $todas = $this->enviar('GET')->assertOk();
        $this->assertSame(['A', 'B', 'Apagada'], collect($todas->json('data'))->pluck('nombre')->all());

        $activas = $this->enviar('GET', '?activas=1')->assertOk();
        $this->assertSame(['A', 'B'], collect($activas->json('data'))->pluck('nombre')->all());
    }

    public function test_crear_editar_y_borrar_con_permisos(): void
    {
        $this->conPermisos();

        $id = $this->enviar('POST', '', ['nombre' => 'Visita', 'orden' => 4])
            ->assertCreated()
            ->assertJsonPath('data.nombre', 'Visita')
            ->assertJsonPath('data.orden', 4)
            ->assertJsonPath('data.activo', true)
            ->json('data.id');

        $this->enviar('PUT', "/{$id}", ['nombre' => 'Visita técnica', 'orden' => 9, 'activo' => false])
            ->assertOk()
            ->assertJsonPath('data.nombre', 'Visita técnica')
            ->assertJsonPath('data.orden', 9)
            ->assertJsonPath('data.activo', false);

        $this->enviar('DELETE', "/{$id}")->assertOk();
        $this->assertDatabaseMissing('crm_etiquetas_actividad', ['id' => $id]);
    }

    public function test_escribir_exige_permiso(): void
    {
        $this->conPermisos(['ver']);
        $etiqueta = $this->etiqueta();

        $this->enviar('POST', '', ['nombre' => 'Nueva'])->assertForbidden();
        $this->enviar('PUT', "/{$etiqueta->id}", ['nombre' => 'Otra'])->assertForbidden();
        $this->enviar('DELETE', "/{$etiqueta->id}")->assertForbidden();
        $this->assertDatabaseHas('crm_etiquetas_actividad', ['id' => $etiqueta->id, 'nombre' => 'Seguimiento']);
    }

    public function test_nombre_unico_por_empresa(): void
    {
        $this->conPermisos();
        $etiqueta = $this->etiqueta(['nombre' => 'Seguimiento']);
        $otra = $this->etiqueta(['nombre' => 'Visita']);
        $this->etiqueta(['nombre' => 'Llamada'], $this->crearOtraEmpresa()->id);

        $duplicada = $this->enviar('POST', '', ['nombre' => 'Seguimiento'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nombre');
        $this->assertStringStartsWith('Ya existe', $duplicada->json('errors.nombre.0'));

        $this->enviar('PUT', "/{$otra->id}", ['nombre' => 'Seguimiento'])->assertStatus(422);
        // Conservar el propio nombre al editar sí se permite.
        $this->enviar('PUT', "/{$etiqueta->id}", ['nombre' => 'Seguimiento', 'orden' => 5])->assertOk();
        // El mismo nombre en otra empresa no choca.
        $this->enviar('POST', '', ['nombre' => 'Llamada'])->assertCreated();
    }

    public function test_validacion_de_campos(): void
    {
        $this->conPermisos();

        $this->enviar('POST', '', [])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->enviar('POST', '', ['nombre' => str_repeat('a', 101)])->assertStatus(422)->assertJsonValidationErrors('nombre');
        $this->enviar('POST', '', ['nombre' => 'X', 'orden' => -1])->assertStatus(422)->assertJsonValidationErrors('orden');
        $this->enviar('POST', '', ['nombre' => 'X', 'orden' => 65536])->assertStatus(422)->assertJsonValidationErrors('orden');
        $this->enviar('POST', '', ['nombre' => 'X', 'activo' => 'quizá'])->assertStatus(422)->assertJsonValidationErrors('activo');
    }

    public function test_otra_empresa_404(): void
    {
        $this->conPermisos();
        $ajena = $this->etiqueta(['nombre' => 'Ajena'], $this->crearOtraEmpresa()->id);

        $this->enviar('PUT', "/{$ajena->id}", ['nombre' => 'Mía'])->assertNotFound();
        $this->enviar('DELETE', "/{$ajena->id}")->assertNotFound();
        $this->assertDatabaseHas('crm_etiquetas_actividad', ['id' => $ajena->id, 'nombre' => 'Ajena']);
    }

    public function test_borrar_etiqueta_usada_responde_422(): void
    {
        $this->conPermisos();
        $enAgenda = $this->etiqueta(['nombre' => 'En agenda']);
        $enActividad = $this->etiqueta(['nombre' => 'En actividad']);
        $libre = $this->etiqueta(['nombre' => 'Libre']);

        CrmAgenda::create([
            'empresa_id' => $this->enterprise->id, 'vendedor_id' => $this->vendedor->id,
            'tipo' => 'tarea', 'titulo' => 'Con etiqueta',
            'fecha_inicio' => now(), 'fecha_fin' => now()->addHour(),
            'etiqueta_id' => $enAgenda->id,
        ]);
        $cliente = CrmCliente::create(['empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente']);
        CrmActividad::create([
            'empresa_id' => $this->enterprise->id, 'tipo' => 'nota',
            'entidad_type'
 => CrmCliente::class, 'entidad_id' => $cliente->id,
            'descripcion' => 'Nota', 'fecha_actividad' => now(),
            'etiqueta_id' => $enActividad->id,
        ]);

        foreach ([$enAgenda, $enActividad] as $usada) {
            $this->enviar('DELETE', "/{$usada->id}")
                ->assertStatus(422)
                ->assertJsonPath('message', self::MENSAJE_USADA);
            $this->assertDatabaseHas('crm_etiquetas_actividad', ['id' => $usada->id]);
        }

        $this->enviar('DELETE', "/{$libre->id}")->assertOk();
        $this->assertDatabaseMissing('crm_etiquetas_actividad', ['id' => $libre->id]);
    }
}
