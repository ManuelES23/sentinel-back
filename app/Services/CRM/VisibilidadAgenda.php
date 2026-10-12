<?php

namespace App\Services\CRM;

use App\Models\CRM\CrmAgenda;
use App\Traits\CRM\VerificaPermisoSubmodulo;
use Illuminate\Support\Facades\Auth;

/**
 * Regla de visibilidad de la Agenda del CRM (mismo patrón que
 * VisibilidadOportunidades):
 *
 * - Con `agenda.equipo` (perfil gerencia) se consulta y se asigna la agenda
 *   de cualquier vendedor de la empresa.
 * - Sin él, solo la del CrmVendedor ACTIVO del usuario en la empresa: pedir
 *   la de otro responde 403 y, sin vendedor activo vinculado, todo 403.
 *
 * `crear` y `editar` NO cuentan como gerencia: el perfil vendedor los tiene.
 * Presupuestos conserva su regla propia (PresupuestoController::resolverVendedorId).
 */
class VisibilidadAgenda
{
    use VerificaPermisoSubmodulo;

    public const MENSAJE_AJENA = 'No puedes ver o modificar la agenda de otro vendedor.';

    public const MENSAJE_SIN_VENDEDOR = VisibilidadOportunidades::MENSAJE_SIN_VENDEDOR;

    public function __construct(private readonly VendedorActualService $vendedores) {}

    public function veAlEquipo(int $empresaId): bool
    {
        return $this->tienePermisoSubmodulo($empresaId, 'agenda', 'agenda', 'equipo');
    }

    /**
     * Vendedor cuya agenda puede consultar o asignar el usuario. Con `equipo`
     * devuelve el solicitado; sin él, 403 si no tiene vendedor activo
     * vinculado o si pide el de otro.
     */
    public function vendedorVisible(int $empresaId, int $solicitado): int
    {
        if ($this->veAlEquipo($empresaId)) {
            return $solicitado;
        }

        $propio = $this->vendedores->vendedorDelUsuario($empresaId, (int) Auth::id());
        abort_unless($propio !== null, 403, self::MENSAJE_SIN_VENDEDOR);
        abort_unless((int) $propio->id === $solicitado, 403, self::MENSAJE_AJENA);

        return $solicitado;
    }

    /** 403 si el evento es de otro vendedor (o el usuario no tiene vendedor activo) y no tiene `equipo`. */
    public function exigirEventoVisible(CrmAgenda $evento): void
    {
        $this->vendedorVisible((int) $evento->empresa_id, (int) $evento->vendedor_id);
    }
}
