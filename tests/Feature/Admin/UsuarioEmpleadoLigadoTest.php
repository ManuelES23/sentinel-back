<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Admin → Usuarios: ligar, cambiar o quitar el empleado de RH de un usuario ya
 * creado (lo que permite, p. ej., que apruebe órdenes de compra).
 */
class UsuarioEmpleadoLigadoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $usuario;
    private Enterprise $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->usuario = User::factory()->create(['role' => 'user']);
        $this->empresa = Enterprise::create(['name' => 'Grupo Espléndido', 'slug' => 'grupoesplendido', 'description' => 'Corporativo', 'is_active' => true]);
    }

    private function empleado(array $attrs = []): Employee
    {
        return Employee::create($attrs + [
            'enterprise_id' => $this->empresa->id, 'employee_number' => 'E' . Str::random(6),
            'first_name' => 'Ana', 'last_name' => 'López', 'hire_date' => '2026-01-01',
            'qr_code' => Str::random(32), 'status' => 'active',
        ]);
    }

    public function test_ligar_un_empleado_a_un_usuario_existente(): void
    {
        Sanctum::actingAs($this->admin);
        $empleado = $this->empleado();

        $this->putJson("/api/users/{$this->usuario->id}", ['employee_id' => $empleado->id])
            ->assertOk()
            ->assertJsonPath('user.employee.id', $empleado->id)
            ->assertJsonPath('user.employee.enterprise_name', 'Grupo Espléndido');

        $this->assertSame($this->usuario->id, $empleado->fresh()->user_id);
        $this->assertTrue(ActivityLog::where('action', 'user_employee_link')->where('model_id', $this->usuario->id)->exists());
    }

    public function test_cambiar_de_empleado_libera_el_anterior(): void
    {
        Sanctum::actingAs($this->admin);
        $anterior = $this->empleado(['user_id' => $this->usuario->id]);
        $nuevo = $this->empleado();

        $this->putJson("/api/users/{$this->usuario->id}", ['employee_id' => $nuevo->id])->assertOk();

        $this->assertNull($anterior->fresh()->user_id);
        $this->assertSame($this->usuario->id, $nuevo->fresh()->user_id);
    }

    public function test_quitar_el_empleado(): void
    {
        Sanctum::actingAs($this->admin);
        $empleado = $this->empleado(['user_id' => $this->usuario->id]);

        $this->putJson("/api/users/{$this->usuario->id}", ['employee_id' => null])
            ->assertOk()->assertJsonPath('user.employee', null);

        $this->assertNull($empleado->fresh()->user_id);
    }

    public function test_editar_sin_mandar_employee_id_no_toca_el_vinculo(): void
    {
        Sanctum::actingAs($this->admin);
        $empleado = $this->empleado(['user_id' => $this->usuario->id]);

        $this->putJson("/api/users/{$this->usuario->id}", ['name' => 'Otro nombre'])->assertOk();

        $this->assertSame($this->usuario->id, $empleado->fresh()->user_id);
        $this->assertFalse(ActivityLog::where('action', 'user_employee_link')->exists());
    }

    public function test_no_se_puede_ligar_un_empleado_que_ya_tiene_otro_usuario(): void
    {
        Sanctum::actingAs($this->admin);
        $otro = User::factory()->create(['name' => 'Pedro Ruiz']);
        $empleado = $this->empleado(['user_id' => $otro->id]);

        $this->putJson("/api/users/{$this->usuario->id}", ['employee_id' => $empleado->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employee_id' => 'Pedro Ruiz']);

        $this->assertSame($otro->id, $empleado->fresh()->user_id);
    }

    public function test_no_se_puede_ligar_un_empleado_inactivo(): void
    {
        Sanctum::actingAs($this->admin);
        $empleado = $this->empleado(['status' => 'inactive']);

        $this->putJson("/api/users/{$this->usuario->id}", ['employee_id' => $empleado->id])
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
    }

    public function test_al_crear_tampoco_se_roba_el_empleado_de_otro_usuario(): void
    {
        Sanctum::actingAs($this->admin);
        $otro = User::factory()->create();
        $empleado = $this->empleado(['user_id' => $otro->id]);

        $this->postJson('/api/users', [
            'name' => 'Nuevo', 'email' => 'nuevo@x.test', 'password' => 'Password123!', 'employee_id' => $empleado->id,
        ])->assertStatus(422)->assertJsonValidationErrors('employee_id');

        $this->assertSame($otro->id, $empleado->fresh()->user_id);
        $this->assertFalse(User::where('email', 'nuevo@x.test')->exists());
    }

    public function test_un_usuario_sin_rol_admin_no_puede_ligar(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'user']));
        $empleado = $this->empleado();

        $this->putJson("/api/users/{$this->usuario->id}", ['employee_id' => $empleado->id])->assertForbidden();
        $this->assertNull($empleado->fresh()->user_id);
    }

    public function test_el_listado_muestra_el_empleado_ligado(): void
    {
        Sanctum::actingAs($this->admin);
        $empleado = $this->empleado(['user_id' => $this->usuario->id]);

        $fila = collect($this->getJson('/api/users')->assertOk()->json())->firstWhere('id', $this->usuario->id);

        $this->assertSame($empleado->id, $fila['employee']['id']);
        $this->assertSame('Ana López', $fila['employee']['full_name']);
        $this->assertNull(collect($this->getJson('/api/users')->json())->firstWhere('id', $this->admin->id)['employee']);
    }
}
