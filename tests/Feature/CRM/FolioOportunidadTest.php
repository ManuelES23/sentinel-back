<?php

namespace Tests\Feature\CRM;

use App\Models\CRM\CrmCliente;
use App\Models\CRM\CrmOportunidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/** Folio OPP-0001 de las oportunidades: consecutivo por empresa, inmutable y buscable. */
class FolioOportunidadTest extends TestCase
{
    use CreatesCrmFixtures;
    use RefreshDatabase;

    protected CrmCliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrmFixtures();
        Sanctum::actingAs($this->actingUser);
        $this->otorgarTodosLosPermisosCrm();
        $this->cliente = CrmCliente::create([
            'empresa_id' => $this->enterprise->id, 'nombre' => 'Cliente', 'estatus' => 'activo',
            'vendedor_id' => $this->vendedor->id,
        ]);
    }

    private function crear(string $nombre = 'Oportunidad', ?int $empresaId = null): CrmOportunidad
    {
        return CrmOportunidad::create([
            'empresa_id' => $empresaId ?? $this->enterprise->id, 'cliente_id' => $this->cliente->id,
            'vendedor_id' => $this->vendedor->id, 'nombre' => $nombre, 'etapa' => 'prospecto',
        ]);
    }

    public function test_asigna_folios_consecutivos_con_cuatro_digitos(): void
    {
        $this->assertSame('OPP-0001', $this->crear('A')->folio);
        $this->assertSame('OPP-0002', $this->crear('B')->folio);
    }

    public function test_el_consecutivo_es_por_empresa(): void
    {
        $otra = $this->crearOtraEmpresa();
        $this->crear('A');
        $this->crear('B');

        $this->assertSame('OPP-0001', $this->crear('De otra empresa', $otra->id)->folio);
        $this->assertSame('OPP-0003', $this->crear('C')->folio);
    }

    public function test_no_reutiliza_el_folio_de_una_oportunidad_borrada(): void
    {
        $a = $this->crear('A');
        $a->delete();

        $this->assertSame('OPP-0002', $this->crear('B')->folio);
    }

    public function test_pasa_de_9999_sin_romperse(): void
    {
        $this->crear('A');
        DB::table('crm_oportunidades')->update(['folio' => 'OPP-9999']);

        $this->assertSame('OPP-10000', $this->crear('B')->folio);
    }

    public function test_el_folio_nunca_cambia_al_editar(): void
    {
        $a = $this->crear('A');
        $a->update(['nombre' => 'Editada', 'folio' => 'OPP-0099']);

        $this->assertSame('OPP-0001', $a->fresh()->folio);
        $this->withHeaders($this->crmHeaders())
            ->putJson("/api/crm/oportunidades/{$a->id}", ['nombre' => 'Otra', 'folio' => 'OPP-0500'])
            ->assertOk()->assertJsonPath('data.folio', 'OPP-0001');
    }

    public function test_el_alta_por_api_ignora_un_folio_enviado_y_lo_devuelve(): void
    {
        $this->crear('A');

        $this->withHeaders($this->crmHeaders())->postJson('/api/crm/oportunidades', [
            'cliente_id' => $this->cliente->id, 'vendedor_id' => $this->vendedor->id,
            'nombre' => 'Nueva', 'monto_esperado' => 100, 'folio' => 'OPP-0777',
        ])->assertCreated()->assertJsonPath('data.folio', 'OPP-0002');
    }

    public function test_el_listado_busca_por_folio(): void
    {
        $a = $this->crear('Maíz amarillo');
        $this->crear('Mango Kent');

        $ids = collect($this->withHeaders($this->crmHeaders())
            ->getJson('/api/crm/oportunidades?search=OPP-0001')->assertOk()->json('data'))->pluck('id');

        $this->assertSame([$a->id], $ids->all());
    }

    public function test_la_migracion_da_folio_a_las_existentes_por_orden_de_creacion_y_empresa(): void
    {
        $otra = $this->crearOtraEmpresa();
        $a = $this->crear('A');
        $b = $this->crear('B');
        $borrada = $this->crear('C');
        $borrada->delete();
        $deOtra = $this->crear('De otra', $otra->id);

        // Estado anterior a la migración: sin folio y sin el índice único.
        DB::table('crm_oportunidades')->update(['folio' => null]);
        \Illuminate\Support\Facades\Schema::table('crm_oportunidades', fn ($t) => $t->dropUnique('crm_oportunidades_empresa_folio_unique'));

        (require database_path('migrations/2026_11_17_100000_add_folio_to_crm_oportunidades.php'))->up();

        $folio = fn ($op) => DB::table('crm_oportunidades')->where('id', $op->id)->value('folio');
        $this->assertSame(['OPP-0001', 'OPP-0002', 'OPP-0003'], [$folio($a), $folio($b), $folio($borrada)]);
        $this->assertSame('OPP-0001', $folio($deOtra));
        $this->assertSame('OPP-0004', $this->crear('D')->folio);
    }

    public function test_la_cotizacion_se_encuentra_por_el_folio_de_su_oportunidad(): void
    {
        $a = $this->crear('A');
        $b = $this->crear('B');
        foreach ([$a, $b] as $i => $op) {
            \App\Models\CRM\CrmCotizacion::create([
                'empresa_id' => $this->enterprise->id, 'oportunidad_id' => $op->id,
                'folio' => 'COT-0000'.($i + 1), 'estado' => 'borrador', 'fecha_emision' => now()->toDateString(),
            ]);
        }

        $respuesta = $this->withHeaders($this->crmHeaders())
            ->getJson('/api/crm/cotizaciones?folio=OPP-0002')->assertOk();

        $this->assertSame(['COT-00002'], collect($respuesta->json('data'))->pluck('folio')->all());
    }
}
