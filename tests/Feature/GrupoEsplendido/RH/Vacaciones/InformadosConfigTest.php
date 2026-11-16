<?php

namespace Tests\Feature\GrupoEsplendido\RH\Vacaciones;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalProcess;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesVacacionesFixtures;
use Tests\TestCase;

class InformadosConfigTest extends TestCase
{
    use RefreshDatabase, CreatesVacacionesFixtures;

    private User $director;

    private User $asistente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVacaciones();
        $this->director = User::factory()->create();
        $this->asistente = User::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function urlPasos(?ApprovalProcess $proceso = null): string
    {
        return '/api/admin/approval-processes/'.($proceso ?? $this->procesoVacaciones)->id.'/steps';
    }

    private function crearPasoUsuario(array $informados): int
    {
        return $this->postJson($this->urlPasos(), [
            'approver_type' => 'user', 'step_order' => 1, 'user_id' => $this->director->id,
            'observer_user_ids' => $informados,
        ])->assertCreated()->json('data.id');
    }

    private function idsInformados(int $pasoId): array
    {
        return DB::table('approval_flow_step_observers')
            ->where('approval_flow_step_id', $pasoId)->orderBy('user_id')->pluck('user_id')->all();
    }

    public function test_un_paso_usuario_especifico_guarda_sus_informados(): void
    {
        $id = $this->crearPasoUsuario([$this->asistente->id]);

        $this->assertDatabaseHas('approval_flow_step_observers', [
            'approval_flow_step_id' => $id, 'user_id' => $this->asistente->id,
        ]);

        $this->getJson($this->urlPasos())->assertOk()
            ->assertJsonPath('data.0.observers.0.id', $this->asistente->id)
            ->assertJsonPath('data.0.observers.0.email', $this->asistente->email);
    }

    public function test_editar_reemplaza_la_lista(): void
    {
        $otra = User::factory()->create();
        $id = $this->crearPasoUsuario([$this->asistente->id]);
        $url = $this->urlPasos()."/{$id}";

        $this->putJson($url, ['observer_user_ids' => [$otra->id]])->assertOk()
            ->assertJsonPath('data.observers.0.id', $otra->id);
        $this->assertSame([$otra->id], $this->idsInformados($id));

        // Sin la clave no se toca.
        $this->putJson($url, ['is_active' => true])->assertOk();
        $this->assertSame([$otra->id], $this->idsInformados($id));

        // Lista vacía la vacía.
        $this->putJson($url, ['observer_user_ids' => []])->assertOk();
        $this->assertSame([], $this->idsInformados($id));
    }

    public function test_no_se_puede_informar_a_quien_aprueba_el_paso(): void
    {
        $this->postJson($this->urlPasos(), [
            'approver_type' => 'user', 'step_order' => 1, 'user_id' => $this->director->id,
            'observer_user_ids' => [$this->director->id],
        ])->assertStatus(422)->assertJsonValidationErrors('observer_user_ids')
            ->assertJsonPath('errors.observer_user_ids.0', 'Quien aprueba este paso ya está informado.');

        $id = $this->crearPasoUsuario([]);
        $this->putJson($this->urlPasos()."/{$id}", ['observer_user_ids' => [$this->director->id]])
            ->assertStatus(422)->assertJsonValidationErrors('observer_user_ids');
        $this->assertSame([], $this->idsInformados($id));
    }

    public function test_otros_tipos_o_procesos_no_guardan_informados(): void
    {
        $id = $this->postJson($this->urlPasos(), [
            'approver_type' => 'department_manager', 'step_order' => 2,
            'observer_user_ids' => [$this->asistente->id],
        ])->assertCreated()->json('data.id');
        $this->assertSame([], $this->idsInformados($id));

        // Otro proceso (el tipo «Usuario específico» ni existe ahí): con puesto tampoco guarda.
        $otro = ApprovalProcess::firstOrCreate(
            ['code' => 'purchase_orders'],
            [
                'name' => 'Órdenes de compra', 'description' => 'Aprobación de órdenes de compra', 'module' => 'compras',
                'entity_type' => PurchaseOrder::class, 'requires_approval' => true, 'is_active' => true,
            ],
        );
        $idOtro = $this->postJson($this->urlPasos($otro), [
            'approver_type' => 'position', 'step_order' => 1, 'position_id' => $this->puesto($this->empresa, 'Gerente', 3, true)->id,
            'observer_user_ids' => [$this->asistente->id],
        ])->assertCreated()->json('data.id');
        $this->assertSame([], $this->idsInformados($idOtro));

        // Cambiar un paso user con informados a otro tipo los borra.
        $idUser = $this->crearPasoUsuario([$this->asistente->id]);
        $this->assertSame([$this->asistente->id], $this->idsInformados($idUser));
        $this->putJson($this->urlPasos()."/{$idUser}", ['approver_type' => 'direct_supervisor'])->assertOk();
        $this->assertSame([], $this->idsInformados($idUser));
    }

    public function test_borrar_el_usuario_borra_su_fila(): void
    {
        $id = $this->crearPasoUsuario([$this->asistente->id]);
        $this->assertSame([$this->asistente->id], $this->idsInformados($id));

        $this->asistente->delete();

        $this->assertSame([], $this->idsInformados($id));
    }

    public function test_la_relacion_observers_devuelve_usuarios(): void
    {
        $id = $this->crearPasoUsuario([$this->asistente->id]);

        $this->assertSame([$this->asistente->id], ApprovalFlowStep::findOrFail($id)->observers->pluck('id')->all());
    }
}
