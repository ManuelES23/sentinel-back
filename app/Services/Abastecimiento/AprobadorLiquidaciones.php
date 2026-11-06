<?php

namespace App\Services\Abastecimiento;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\Abastecimiento\Liquidacion;
use App\Models\Abastecimiento\LiquidacionAprobacion;
use App\Models\ApprovalFlowStep;
use App\Models\Employee;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\ActivosFijos\AlcanceActivos;
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
 * Siempre: acceso activo a la empresa (o ser admin), como exigen sus rutas; quien creó, editó o envió la liquidación (en cualquier ronda) no decide, y nadie decide dos pasos de la misma ronda.
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

        // Las rutas de la empresa exigen admin o acceso activo a ella: sin eso nadie podría decidir el paso.
        $empresa = Enterprise::find($liquidacion->enterprise_id);
        if ($empresa === null || ! AlcanceActivos::tieneAcceso($usuario, $empresa)) {
            return false;
        }

        if (($paso->rules ?? []) === []) {
            return app(ContextoAbastecimiento::class)->puede($usuario, $empresa, 'liquidaciones', 'aprobar');
        }

        $bandera = $accion === 'rechazar' ? 'can_reject' : 'can_approve';

        return self::reglasDelPaso($paso)
            ->filter(fn (ApprovalFlowStep $regla) => (bool) $regla->{$bandera})
            ->contains(fn (ApprovalFlowStep $regla) => self::cumpleRegla($usuario, $liquidacion, $regla));
    }

    /**
     * Lo que el usuario puede hacer con el paso (`aprobar` y/o `rechazar`) evaluando las comprobaciones comunes y las
     * reglas UNA sola vez (la bandeja del perfil lo corre por cada liquidación en revisión). Mismo resultado que
     * llamar a `puedeDecidir` con cada acción: una acción es posible si alguna regla que el usuario cumple la permite.
     *
     * @return array{aprobar: bool, rechazar: bool}
     */
    public static function accionesPara(User $usuario, Liquidacion $liquidacion, LiquidacionAprobacion $paso): array
    {
        $nada = ['aprobar' => false, 'rechazar' => false];
        if ($paso->decision !== 'waiting') {
            return $nada;
        }
        if (self::esCreadorOEnviador($usuario, $liquidacion) || self::decidioOtroPaso($usuario, $paso)) {
            return $nada;
        }

        $empresa = Enterprise::find($liquidacion->enterprise_id);
        if ($empresa === null || ! AlcanceActivos::tieneAcceso($usuario, $empresa)) {
            return $nada;
        }

        if (($paso->rules ?? []) === []) {
            $puede = app(ContextoAbastecimiento::class)->puede($usuario, $empresa, 'liquidaciones', 'aprobar');

            return ['aprobar' => $puede, 'rechazar' => $puede];
        }

        $cumplidas = self::reglasDelPaso($paso)
            ->filter(fn (ApprovalFlowStep $regla) => self::cumpleRegla($usuario, $liquidacion, $regla));

        return [
            'aprobar' => $cumplidas->contains(fn (ApprovalFlowStep $regla) => (bool) $regla->can_approve),
            'rechazar' => $cumplidas->contains(fn (ApprovalFlowStep $regla) => (bool) $regla->can_reject),
        ];
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

    /**
     * Separación de funciones: quien creó, editó (aunque el borrador fuera de otro) o envió la liquidación en
     * cualquier ronda (`intervinieron`, más `created_by` y `enviada_by`) no la aprueba ni la rechaza. La bandeja
     * del perfil aplica el mismo filtro en SQL (`PendingApprovalController::calcularLiquidacionesPara`).
     */
    public static function esCreadorOEnviador(User $usuario, Liquidacion $liquidacion): bool
    {
        return in_array((int) $usuario->id, $liquidacion->idsQueIntervinieron(), true);
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
        // `employees.user_id` no es único: solo cuentan las fichas de la empresa de la liquidación.
        $empleados = Employee::where('user_id', $usuario->id)
            ->where('enterprise_id', $liquidacion->enterprise_id)
            ->with('position')
            ->get();

        if ($regla->approver_type === ApprovalFlowStep::TYPE_USER) {
            return $regla->user_id !== null
                && (int) $regla->user_id === (int) $usuario->id
                && ($empleados->isEmpty() || $empleados->contains(fn (Employee $e) => $e->status === Employee::STATUS_ACTIVE));
        }

        if ($regla->approver_type === 'position') {
            return $empleados->contains(fn (Employee $e) => $e->status === Employee::STATUS_ACTIVE && $regla->matchesEmployee($e));
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
