<?php
// sentinel-back/tests/Feature/PendingApprovalTimeClockReviewTest.php
namespace Tests\Feature;

use App\Models\Application;
use App\Models\Enterprise;
use App\Models\Module;
use App\Models\Submodule;
use App\Models\TimeClockCheck;
use App\Models\UserEnterpriseAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesRhFixtures;
use Tests\TestCase;

class PendingApprovalTimeClockReviewTest extends TestCase
{
    use RefreshDatabase, CreatesRhFixtures;

    /**
     * El carve-out 'time_clock_check_review' vive en
     * PendingApprovalController::summary() (mismo punto de integración que
     * getFieldCheckReviewProcessEntry() / 'field_check_review' — ver
     * app/Http/Controllers/Api/PendingApprovalController.php línea ~33).
     * index() ('/api/pending-approvals') no incluye ninguno de los dos
     * carve-outs de submódulo, así que el test real pega a
     * '/api/pending-approvals/summary'.
     */
    public function test_entry_absent_without_submodule_access(): void
    {
        [, $enterprise] = $this->createAuthenticatedRhUser();
        $employee = $this->createEmployee($enterprise->id);
        TimeClockCheck::create([
            'client_uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'type' => 'check_in',
            'checked_at' => now(),
            'verification_status' => 'low_confidence',
            'clock_skew_seconds' => 0,
        ]);

        $response = $this->getJson('/api/pending-approvals/summary');

        $response->assertStatus(200);
        $codes = collect($response->json('data.processes'))->pluck('code');
        $this->assertFalse($codes->contains('time_clock_check_review'));
    }

    /**
     * Hallazgo #1 de la revision final del branch (Fix 1a): RH es una app
     * corporativa multi-empresa — applications.slug='rh' vive bajo una sola
     * empresa (donde se crea el submodulo 'revision-checador' aqui abajo),
     * pero los empleados de RH abarcan varias empresas reales. El conteo
     * del carve-out debe escalar a TODAS las empresas donde el usuario
     * tiene UserEnterpriseAccess activo, no solo a la empresa dueña de la
     * app RH.
     */
    public function test_summary_time_clock_review_count_spans_all_user_enterprises(): void
    {
        [$user, $enterpriseA] = $this->createAuthenticatedRhUser();
        $enterpriseB = Enterprise::create([
            'name' => 'Grupo Espléndido Test B',
            'slug' => 'grupoesplendido-test-b',
            'description' => 'Segunda empresa de prueba para RH',
            'is_active' => true,
        ]);
        UserEnterpriseAccess::create([
            'user_id' => $user->id,
            'enterprise_id' => $enterpriseB->id,
            'is_active' => true,
            'granted_at' => now(),
        ]);

        $employeeA = $this->createEmployee($enterpriseA->id);
        $employeeB = $this->createEmployee($enterpriseB->id);

        TimeClockCheck::create([
            'client_uuid' => (string) Str::uuid(),
            'employee_id' => $employeeA->id,
            'type' => 'check_in',
            'checked_at' => now(),
            'verification_status' => 'low_confidence',
            'clock_skew_seconds' => 0,
        ]);
        TimeClockCheck::create([
            'client_uuid' => (string) Str::uuid(),
            'employee_id' => $employeeB->id,
            'type' => 'check_in',
            'checked_at' => now(),
            'verification_status' => 'low_confidence',
            'clock_skew_seconds' => 0,
        ]);

        // La app 'rh' / submodulo 'revision-checador' se registra bajo UNA
        // sola empresa (enterpriseA) — asi es como vive en produccion
        // (applications.enterprise_id=4, grupoesplendido). El gate de
        // permiso solo necesita user_submodule_access sobre ESE submodulo;
        // lo que cambia con el fix es el alcance de empresas que se cuentan
        // una vez que el gate pasa.
        $this->grantTimeClockReviewPermission($user, $enterpriseA);

        $response = $this->getJson('/api/pending-approvals/summary');

        $response->assertStatus(200);
        $entry = collect($response->json('data.processes'))->firstWhere('code', 'time_clock_check_review');
        $this->assertNotNull($entry);
        $this->assertSame(2, $entry['pending_count']);
    }

    /**
     * Inserta directamente en user_submodule_access (sin filas en
     * user_submodule_permissions) replicando el patron real usado en
     * PendingApprovalControllerTest::grantFieldCheckReviewPermission() para
     * el carve-out gemelo de campo (Splendid Farms) — sin tipos de permiso
     * definidos para el submodulo, el carve-out de produccion trata el
     * acceso como permitido.
     */
    private function grantTimeClockReviewPermission($user, Enterprise $enterprise): void
    {
        $application = Application::firstOrCreate(
            ['slug' => 'rh', 'enterprise_id' => $enterprise->id],
            ['name' => 'RH', 'description' => 'RH', 'icon' => 'Users', 'path' => '/rh', 'order' => 1, 'is_active' => true]
        );
        $module = Module::firstOrCreate(
            ['slug' => 'asistencia', 'application_id' => $application->id],
            ['name' => 'Asistencia', 'description' => 'Asistencia', 'icon' => 'Clock', 'path' => '/asistencia', 'order' => 1, 'is_active' => true]
        );
        $submodule = Submodule::firstOrCreate(
            ['slug' => 'revision-checador', 'module_id' => $module->id],
            ['name' => 'Revisión de Checador', 'description' => 'Revisión de Checador', 'icon' => 'ShieldCheck', 'path' => '/revision-checador', 'order' => 1, 'is_active' => true]
        );

        DB::table('user_submodule_access')->insert([
            'user_id' => $user->id,
            'submodule_id' => $submodule->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
