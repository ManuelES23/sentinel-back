<?php

namespace App\Services\Abastecimiento;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionAprobacion;
use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use App\Support\PermisoSubmodulo;
use Illuminate\Support\Collection;

/**
 * Quién puede decidir cada paso de la cadena de una liquidación (plan 2, rulings 2-5), con el patrón de
 * AprobadorVacaciones y AprobadorOrdenCompra. Los pasos congelan los ids de las reglas (`rules`):
 * - «Usuario específico»: el usuario de la regla (si además es empleado, debe estar activo).
 * - «Puesto»: empleado activo de la empresa de la liquidación, con usuario, cuyo puesto coincide con la
 *   regla y puede aprobar (alcance toda la empresa).
 * - Paso sin reglas (`rules = []`, sin cadena configurada): quien tenga `liquidaciones.aprobar` en la
 *   empresa; el administrador del sistema entra por el bypass de `ContextoAbastecimiento::puede`.
 * Siempre: quien creó o envió la liquidación no decide, y nadie decide dos pasos de la misma ronda.
 */
class AprobadorLiquidaciones
{
    public const DESCRIPCION_PERMISO = 'Quien tenga el permiso de aprobar';

    /**
     * ¿Puede el usuario decidir el paso? `$accion` = `aprobar` | `rechazar` (cada regla lo permite o no
     * con `can_approve` / `can_reject`). Un paso ya decidido nunca se puede decidir.
     */
    public static function puedeDecidir(User $usuario, Liquidacion $liquidacion, LiquidacionAprobacion $paso, string $accion = 'aprobar'): bool
    {
        if ($paso->decision !== 'waiting') {
            return false;
        }
        if (self::esCreadorOEnviador($usuario, $liquidacion) || self::decidioOtroPaso($usuario, $paso)) {
            return false;
        }

        if (($paso->rules ?? []) === []) {
            $empresa = Enterprise::find($liquidacion->enterprise_id);

            return $empresa !== null
                && app(ContextoAbastecimiento::class)->puede($usuario, $empresa, 'liquidaciones', 'aprobar');
        }

        $bandera = $accion === 'rechazar' ? 'can_reject' : 'can_approve';

        return self::reglasDelPaso($paso)
            ->filter(fn (ApprovalFlowStep $regla) => (bool) $regla->{$bandera})
            ->contains(fn (ApprovalFlowStep $regla) => self::cumpleRegla($usuario, $liquidacion, $regla));
    }

    /** @return Collection<int, User> usuarios que hoy podrían aprobar el paso (sin repetir) */
    public static function elegibles(Liquidacion $liquidacion, LiquidacionAprobacion $paso): Collection
    {
        $candidatos = ($paso->rules ?? []) === []
            ? self::candidatosPorPermiso($liquidacion)
            : self::reglasDelPaso($paso)
                ->filter(fn (ApprovalFlowStep $regla) => (bool) $regla->can_approve)
                ->flatMap(fn (ApprovalFlowStep $regla) => self::candidatosDeRegla($regla, $liquidacion));

        return $candidatos->unique('id')
            ->filter(fn (User $usuario) => self::puedeDecidir($usuario, $liquidacion, $paso))
            ->values();
    }

    public static function esCreadorOEnviador(User $usuario, Liquidacion $liquidacion): bool
    {
        return ($liquidacion->created_by !== null && (int) $liquidacion->created_by === (int) $usuario->id)
            || ($liquidacion->enviada_by !== null && (int) $liquidacion->enviada_by === (int) $usuario->id);
    }

    /** ¿Ya decidió (aprobó o rechazó) otro paso de la misma ronda? */
    public static function decidioOtroPaso(User $usuario, LiquidacionAprobacion $paso): bool
    {
        return LiquidacionAprobacion::where('liquidacion_id', $paso->liquidacion_id)
            ->where('ronda', $paso->ronda)
            ->when($paso->exists, fn ($q) => $q->where('id', '!=', $paso->id))
            ->where('decided_by', $usuario->id)
            ->whereIn('decision', ['approved', 'rejected'])
            ->exists();
    }

    /** @return Collection<int, ApprovalFlowStep> */
    private static function reglasDelPaso(LiquidacionAprobacion $paso): Collection
    {
        return ApprovalFlowStep::with('position')
            ->whereIn('id', $paso->rules ?? [])
            ->where('is_active', true)
            ->get();
    }

    private static function cumpleRegla(User $usuario, Liquidacion $liquidacion, ApprovalFlowStep $regla): bool
    {
        $empleado = $usuario->employee()->first();

        if ($regla->approver_type === ApprovalFlowStep::TYPE_USER) {
            return $regla->user_id !== null
                && (int) $regla->user_id === (int) $usuario->id
                && ($empleado === null || $empleado->status === Employee::STATUS_ACTIVE);
        }

        if ($regla->approver_type === 'position') {
            return $empleado !== null
                && $empleado->status === Employee::STATUS_ACTIVE
                && (int) $empleado->enterprise_id === (int) $liquidacion->enterprise_id
                && $regla->matchesEmployee($empleado);
        }

        // Cualquier otro tipo no se admite en este proceso: nadie cumple (falla cerrado).
        return false;
    }

    /** @return Collection<int, User> */
    private static function candidatosDeRegla(ApprovalFlowStep $regla, Liquidacion $liquidacion): Collection
    {
        if ($regla->approver_type === ApprovalFlowStep::TYPE_USER) {
            return $regla->user_id ? User::whereKey($regla->user_id)->get() : collect();
        }

        if ($regla->approver_type === 'position' && $regla->position_id) {
            return Employee::with('user')
                ->where('status', Employee::STATUS_ACTIVE)
                ->where('enterprise_id', $liquidacion->enterprise_id)
                ->where('position_id', $regla->position_id)
                ->whereNotNull('user_id')
                ->get()
                ->map(fn (Employee $empleado) => $empleado->user)
                ->filter()
                ->values();
        }

        return collect();
    }

    /** @return Collection<int, User> con la concesión `liquidaciones.aprobar` y los administradores del sistema */
    private static function candidatosPorPermiso(Liquidacion $liquidacion): Collection
    {
        $ids = PermisoSubmodulo::usuariosCon((int) $liquidacion->enterprise_id, 'administration', 'abastecimiento', 'liquidaciones', 'aprobar');

        return User::whereIn('id', $ids)->orWhereIn('role', EnsureUserIsAdmin::ROLES_ADMIN)->get();
    }
}
