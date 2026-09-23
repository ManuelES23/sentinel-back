<?php

namespace App\Services\CRM;

use App\Exceptions\CRM\VendedorNoVinculadoException;
use App\Models\CRM\CrmVendedor;
use App\Models\User;
use App\Traits\CRM\VerificaPermisoSubmodulo;

/**
 * Resuelve sobre qué vendedor actúa el usuario en Mi día y en los flujos de
 * seguimiento de la fase 2. Regla: su propio vendedor por defecto; otro
 * vendedor solo con el permiso mi-dia.equipo (perfil gerencia). No usa la
 * regla de Agenda (crear/editar = gerencia) porque el perfil vendedor
 * también tiene esos permisos.
 */
class VendedorActualService
{
    use VerificaPermisoSubmodulo;

    public function vendedorDelUsuario(int $empresaId, int $userId): ?CrmVendedor
    {
        return CrmVendedor::where('empresa_id', $empresaId)
            ->where('user_id', $userId)
            ->activo()
            ->first();
    }

    public function puedeVerEquipo(int $empresaId): bool
    {
        return $this->tienePermisoSubmodulo($empresaId, 'mi-dia', 'mi-dia', 'equipo');
    }

    /**
     * @throws VendedorNoVinculadoException si se pide el propio y no existe.
     */
    public function resolver(int $empresaId, User $user, ?int $vendedorIdSolicitado): CrmVendedor
    {
        $propio = $this->vendedorDelUsuario($empresaId, $user->id);

        if ($vendedorIdSolicitado === null || ($propio && $propio->id === $vendedorIdSolicitado)) {
            if (! $propio) {
                throw new VendedorNoVinculadoException();
            }

            return $propio;
        }

        abort_unless(
            $this->puedeVerEquipo($empresaId),
            403,
            'No puedes ver ni asignar trabajo de otro vendedor.',
        );

        $otro = CrmVendedor::where('empresa_id', $empresaId)->find($vendedorIdSolicitado);
        abort_unless($otro, 404, 'Vendedor no encontrado.');

        return $otro;
    }
}
